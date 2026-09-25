import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { RuleValueInput } from '@/components/segments/RuleValueInput';
import type { RuleTreeNode, RuleWhitelist } from '@/types/segments';

type Props = {
    node: RuleTreeNode;
    whitelist: RuleWhitelist;
    errors: Map<string, string>;
    depth: number;
    onChange: (
        id: string,
        updater: (node: RuleTreeNode) => RuleTreeNode,
    ) => void;
    onRemove: (id: string) => void;
    onAddCondition: (groupId: string) => void;
    onAddGroup: (groupId: string) => void;
};

const UNIT_LABELS: Record<'days' | 'toman', string> = {
    days: 'روز',
    toman: 'تومان',
};

/** Fields where offering a unit toggle actually means something — everything else stays unitless. */
function suggestedUnits(fieldName: string): ('days' | 'toman')[] {
    if (fieldName.endsWith('_days')) {
        return ['days'];
    }

    if (
        ['total_revenue', 'monetary', 'aov', 'clv_historical'].includes(
            fieldName,
        )
    ) {
        return ['toman'];
    }

    return [];
}

/** One node of the rule tree: a Group (AND/OR + nested children) or a Condition (field/operator/value/unit). Recurses on itself for nested groups — CLAUDE.md §2: function component, TypeScript strict, no `any`. */
export function RuleNodeEditor({
    node,
    whitelist,
    errors,
    depth,
    onChange,
    onRemove,
    onAddCondition,
    onAddGroup,
}: Props) {
    const error = errors.get(node.id);

    if (node.kind === 'group') {
        return (
            <div
                className="border-border bg-muted/30 space-y-3 rounded-md border p-3"
                dir="rtl"
            >
                <div className="flex items-center justify-between gap-2">
                    <Select
                        value={node.op}
                        onValueChange={(op) =>
                            onChange(node.id, (n) =>
                                n.kind === 'group'
                                    ? { ...n, op: op as 'AND' | 'OR' }
                                    : n,
                            )
                        }
                    >
                        <SelectTrigger
                            aria-label="نوع ترکیب شرط‌ها"
                            className="w-28"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="AND">و (AND)</SelectItem>
                            <SelectItem value="OR">یا (OR)</SelectItem>
                        </SelectContent>
                    </Select>

                    {depth > 0 && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="حذف این گروه"
                            onClick={() => onRemove(node.id)}
                        >
                            <Trash2 className="size-4" aria-hidden="true" />
                        </Button>
                    )}
                </div>

                {error && (
                    <p role="alert" className="text-destructive text-sm">
                        {error}
                    </p>
                )}

                <div className="space-y-2 border-e-2 pe-3">
                    {node.children.map((child) => (
                        <RuleNodeEditor
                            key={child.id}
                            node={child}
                            whitelist={whitelist}
                            errors={errors}
                            depth={depth + 1}
                            onChange={onChange}
                            onRemove={onRemove}
                            onAddCondition={onAddCondition}
                            onAddGroup={onAddGroup}
                        />
                    ))}
                </div>

                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onAddCondition(node.id)}
                    >
                        <Plus className="size-4" aria-hidden="true" />
                        افزودن شرط
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onAddGroup(node.id)}
                    >
                        <Plus className="size-4" aria-hidden="true" />
                        افزودن گروه
                    </Button>
                </div>
            </div>
        );
    }

    const field =
        node.field === null
            ? undefined
            : whitelist.fields.find((f) => f.name === node.field);
    const availableOperators = whitelist.operators.filter(
        (op) =>
            field === undefined ||
            op.behaviorOnly === (field.group === 'behavior'),
    );
    const operator =
        node.operator === null
            ? undefined
            : whitelist.operators.find((o) => o.name === node.operator);
    const units = field === undefined ? [] : suggestedUnits(field.name);

    return (
        <div className="space-y-1.5" dir="rtl">
            <div className="flex flex-wrap items-center gap-2">
                <Select
                    value={node.field ?? ''}
                    onValueChange={(fieldName) =>
                        onChange(node.id, (n) =>
                            n.kind === 'condition'
                                ? {
                                      ...n,
                                      field: fieldName,
                                      operator: null,
                                      value: null,
                                      unit: null,
                                  }
                                : n,
                        )
                    }
                >
                    <SelectTrigger
                        aria-label="فیلد"
                        aria-invalid={!!error}
                        className="w-44"
                    >
                        <SelectValue placeholder="فیلد را انتخاب کنید" />
                    </SelectTrigger>
                    <SelectContent>
                        {whitelist.fields.map((f) => (
                            <SelectItem key={f.name} value={f.name}>
                                {f.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={node.operator ?? ''}
                    disabled={field === undefined}
                    onValueChange={(op) =>
                        onChange(node.id, (n) =>
                            n.kind === 'condition'
                                ? { ...n, operator: op, value: null }
                                : n,
                        )
                    }
                >
                    <SelectTrigger
                        aria-label="عملگر"
                        aria-invalid={!!error}
                        className="w-44"
                    >
                        <SelectValue placeholder="عملگر را انتخاب کنید" />
                    </SelectTrigger>
                    <SelectContent>
                        {availableOperators.map((op) => (
                            <SelectItem key={op.name} value={op.name}>
                                {op.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {field !== undefined && operator !== undefined && (
                    <RuleValueInput
                        fieldName={field.name}
                        valueShape={operator.valueShape}
                        value={node.value}
                        aria-invalid={!!error}
                        onChange={(value) =>
                            onChange(node.id, (n) =>
                                n.kind === 'condition' ? { ...n, value } : n,
                            )
                        }
                    />
                )}

                {units.length > 0 && (
                    <Select
                        value={node.unit ?? 'none'}
                        onValueChange={(unit) =>
                            onChange(node.id, (n) =>
                                n.kind === 'condition'
                                    ? {
                                          ...n,
                                          unit:
                                              unit === 'none'
                                                  ? null
                                                  : (unit as 'days' | 'toman'),
                                      }
                                    : n,
                            )
                        }
                    >
                        <SelectTrigger aria-label="واحد" className="w-28">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">بدون واحد</SelectItem>
                            {units.map((unit) => (
                                <SelectItem key={unit} value={unit}>
                                    {UNIT_LABELS[unit]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="حذف این شرط"
                    onClick={() => onRemove(node.id)}
                >
                    <Trash2 className="size-4" aria-hidden="true" />
                </Button>
            </div>

            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
        </div>
    );
}
