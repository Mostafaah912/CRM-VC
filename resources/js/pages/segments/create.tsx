import { Head } from '@inertiajs/react';
import { SegmentForm } from '@/components/segments/SegmentForm';
import { dashboard } from '@/routes';
import {
    index as segmentsIndex,
    store as segmentsStore,
} from '@/routes/segments';
import type { RuleWhitelist } from '@/types/segments';

type Props = {
    whitelist: RuleWhitelist;
};

export default function SegmentsCreate({ whitelist }: Props) {
    return (
        <>
            <Head title="سگمنت جدید" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">سگمنت جدید</h1>

                <div className="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4">
                    <SegmentForm
                        whitelist={whitelist}
                        action={segmentsStore.url()}
                        submitLabel="ساخت سگمنت"
                    />
                </div>
            </div>
        </>
    );
}

SegmentsCreate.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'سگمنت‌ها', href: segmentsIndex() },
    ],
};
