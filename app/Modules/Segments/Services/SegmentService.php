<?php

declare(strict_types=1);

namespace App\Modules\Segments\Services;

use App\Models\User;
use App\Modules\Core\Services\AuditService;
use App\Modules\Core\Services\PermissionService;
use App\Modules\Segments\Enums\SegmentType;
use App\Modules\Segments\Events\CustomerEnteredSegment;
use App\Modules\Segments\Events\CustomerLeftSegment;
use App\Modules\Segments\Events\SegmentEvaluated;
use App\Modules\Segments\Exceptions\SegmentException;
use App\Modules\Segments\Models\Segment;
use App\Modules\Segments\Support\SegmentEvaluationResult;
use App\Support\PhoneMask;
use App\Support\PostgresStatementTimeout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PRD §17 "ارزیابی": evaluate() / preview() / export() — the only place a Segment's `rule` is ever
 * run. Every compile always goes through RuleCompiler (P5-03), which itself runs RuleValidator
 * (P5-02) first — this class never touches customers/customer_metrics/order_items rows on its own,
 * only through that one compiled query.
 */
final class SegmentService
{
    /** PostgreSQL SQLSTATE for "query_canceled" — what a statement_timeout cancellation reports as. */
    private const TIMEOUT_SQLSTATE = '57014';

    /** PRD §17: "bulk INSERT", chunked so a large segment never builds one giant INSERT statement. */
    private const INSERT_CHUNK_SIZE = 1000;

    public function __construct(
        private readonly AuditService $audit,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * PRD §17: compile -> pluck ids -> transaction (DELETE members; bulk INSERT) -> UPDATE
     * member_count -> diff -> CustomerEntered/LeftSegment (recorded only). Only the destructive
     * DELETE+INSERT swap is transactional — a crash before it starts touches nothing, a crash
     * during it rolls back to the previous, still-consistent membership.
     *
     * @throws SegmentException when `$segment` is not `dynamic`
     */
    public function evaluate(Segment $segment): SegmentEvaluationResult
    {
        if ($segment->type !== SegmentType::Dynamic) {
            throw SegmentException::notDynamic($segment->type);
        }

        $startedAt = microtime(true);

        $newIds = $this->intIds(RuleCompiler::compile($segment->rule ?? [])->pluck('customers.id')->all());
        $oldIds = $this->intIds(DB::table('segment_members')->where('segment_id', $segment->id)->pluck('customer_id')->all());

        DB::transaction(function () use ($segment, $newIds): void {
            DB::table('segment_members')->where('segment_id', $segment->id)->delete();

            $now = now();

            foreach (array_chunk($newIds, self::INSERT_CHUNK_SIZE) as $chunk) {
                DB::table('segment_members')->insert(array_map(
                    static fn (int $customerId): array => ['segment_id' => $segment->id, 'customer_id' => $customerId, 'added_at' => $now],
                    $chunk,
                ));
            }
        });

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $segment->forceFill([
            'member_count' => count($newIds),
            'last_evaluated_at' => now(),
            'last_eval_ms' => $elapsedMs,
        ])->save();

        $entered = array_values(array_diff($newIds, $oldIds));
        $left = array_values(array_diff($oldIds, $newIds));

        foreach ($entered as $customerId) {
            CustomerEnteredSegment::dispatch($segment->id, $customerId);
        }

        foreach ($left as $customerId) {
            CustomerLeftSegment::dispatch($segment->id, $customerId);
        }

        SegmentEvaluated::dispatch($segment->id, count($newIds), count($entered), count($left), $elapsedMs);

        return new SegmentEvaluationResult($segment->id, count($newIds), count($entered), count($left), $elapsedMs);
    }

    /**
     * PRD §17: "preview: compile->count() with statement_timeout = 5s". A rule expensive enough to
     * threaten the database gets a clear, Persian, recoverable error — never a bare 500.
     *
     * @throws SegmentException when the query is cancelled by the timeout
     */
    public function preview(Segment $segment): int
    {
        try {
            return PostgresStatementTimeout::run(
                (int) config('segments.preview_timeout_ms', 5000),
                fn (): int => RuleCompiler::compile($segment->rule ?? [])->count(),
            );
        } catch (QueryException $e) {
            if ($e->getCode() === self::TIMEOUT_SQLSTATE) {
                throw SegmentException::previewTimedOut();
            }

            throw $e;
        }
    }

    /**
     * PRD §17/P5-05: the Rule Builder's live preview of a draft rule that has no Segment row yet
     * (create flow) or may differ from what is saved (edit flow, before save). Builds the same
     * never-persisted Segment `preview()` already only reads `->rule` from, so the Controller layer
     * never has to import `Segments\Models\Segment` itself (CLAUDE.md §1: Controller -> Service ->
     * Model, never Controller -> Model).
     *
     * @param  array<mixed>  $rule
     *
     * @throws SegmentException when the query is cancelled by the timeout
     */
    public function previewRule(array $rule): int
    {
        return $this->preview(new Segment(['rule' => $rule]));
    }

    /**
     * customers.export (PRD §20/§21 T1: bulk PII release is always audited). Full phone requires
     * customers.view_full_phone on top of that; otherwise every row is masked, same as the customer
     * list (P3-01) — export is a separate, audited permission, not a way around the mask.
     *
     * Format decision (PRD is silent here): CSV with a UTF-8 BOM (so Excel on Windows — HeyMode's
     * likely audience — renders Persian text correctly instead of mojibake) and a database cursor
     * streamed straight into the HTTP response, so a large segment is never buffered in memory.
     * Columns are a deliberately minimal identity/metrics set; PRD names no export schema.
     *
     * @throws SegmentException when `$user` lacks customers.export
     */
    public function export(Segment $segment, User $user): StreamedResponse
    {
        if ($this->permissions->denies($user, 'customers', 'export')) {
            throw SegmentException::exportForbidden();
        }

        $canViewFullPhone = $this->permissions->allows($user, 'customers', 'view_full_phone');

        $this->audit->recordUser(
            user: $user,
            action: 'segment.exported',
            auditableType: Segment::class,
            auditableId: $segment->id,
            after: ['segment_id' => $segment->id, 'member_count' => $segment->member_count],
            source: 'segments',
        );

        return response()->streamDownload(function () use ($segment, $canViewFullPhone): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open php://output for the segment export stream.');
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['customer_id', 'phone', 'display_name', 'province', 'city', 'status', 'lifecycle_stage', 'total_orders', 'total_revenue', 'rfm_segment']);

            DB::table('segment_members')
                ->join('customers', 'customers.id', '=', 'segment_members.customer_id')
                ->leftJoin('customer_metrics', 'customer_metrics.customer_id', '=', 'customers.id')
                ->where('segment_members.segment_id', $segment->id)
                ->orderBy('customers.id')
                ->select([
                    'customers.id', 'customers.phone_normalized', 'customers.display_name',
                    'customers.province', 'customers.city', 'customers.status', 'customers.lifecycle_stage',
                    'customer_metrics.total_orders', 'customer_metrics.total_revenue', 'customer_metrics.rfm_segment',
                ])
                ->cursor()
                ->each(function (object $row) use ($handle, $canViewFullPhone): void {
                    fputcsv($handle, [
                        $row->id,
                        $canViewFullPhone ? $row->phone_normalized : PhoneMask::mask((string) $row->phone_normalized),
                        $row->display_name,
                        $row->province,
                        $row->city,
                        $row->status,
                        $row->lifecycle_stage,
                        $row->total_orders,
                        $row->total_revenue,
                        $row->rfm_segment,
                    ]);
                });

            fclose($handle);
        }, "segment-{$segment->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private function intIds(array $ids): array
    {
        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
