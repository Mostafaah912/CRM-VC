import { Head } from '@inertiajs/react';
import { SegmentForm } from '@/components/segments/SegmentForm';
import { dashboard } from '@/routes';
import {
    index as segmentsIndex,
    update as segmentsUpdate,
} from '@/routes/segments';
import type { RuleWhitelist, SegmentFormValues } from '@/types/segments';

type Props = {
    segment: SegmentFormValues;
    whitelist: RuleWhitelist;
};

export default function SegmentsEdit({ segment, whitelist }: Props) {
    return (
        <>
            <Head title={`ویرایش سگمنت «${segment.name}»`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-medium">
                    ویرایش سگمنت «{segment.name}»
                </h1>

                <div className="border-sidebar-border/70 dark:border-sidebar-border max-w-3xl rounded-xl border p-4">
                    <SegmentForm
                        whitelist={whitelist}
                        action={segmentsUpdate.url(segment.id)}
                        submitLabel="ذخیره تغییرات"
                        initialName={segment.name}
                        initialDescription={segment.description}
                        initialRule={segment.rule}
                        locked={segment.is_system}
                    />
                </div>
            </div>
        </>
    );
}

SegmentsEdit.layout = {
    breadcrumbs: [
        { title: 'داشبورد', href: dashboard() },
        { title: 'سگمنت‌ها', href: segmentsIndex() },
    ],
};
