/** PRD §17 whitelist, served from the backend (RuleWhitelistPresenter, P5-01/05) — never hand-copied here. */

export type RuleFieldGroup = 'customer' | 'metrics' | 'behavior';

export type RuleValueShape = 'scalar' | 'list' | 'range' | 'none';

export type RuleField = {
    name: string;
    group: RuleFieldGroup;
    label: string;
};

export type RuleOperatorMeta = {
    name: string;
    label: string;
    behaviorOnly: boolean;
    valueShape: RuleValueShape;
};

export type RuleLimits = {
    maxDepth: number;
    maxNodes: number;
    minChildren: number;
    maxChildren: number;
    maxListValues: number;
};

export type RuleWhitelist = {
    fields: RuleField[];
    operators: RuleOperatorMeta[];
    limits: RuleLimits;
};

/** PRD §17 JSON Rule Schema. `id` is a client-only key (React lists, error lookup) — stripped before the wire form is sent anywhere. */

export type RuleGroupOp = 'AND' | 'OR';

export type RuleScalarValue = string | number;

export type RuleValue = RuleScalarValue | RuleScalarValue[];

export type RuleConditionNode = {
    id: string;
    kind: 'condition';
    field: string | null;
    operator: string | null;
    value: RuleValue | null;
    unit: 'days' | 'toman' | null;
};

export type RuleGroupNode = {
    id: string;
    kind: 'group';
    op: RuleGroupOp;
    children: RuleTreeNode[];
};

export type RuleTreeNode = RuleGroupNode | RuleConditionNode;

/** The PRD §17 wire shape a condition takes once compiled for the server — `id`/`kind` never leave the browser. */
export type WireCondition = {
    field: string;
    operator: string;
    value?: RuleValue;
    unit?: 'days' | 'toman';
};

export type WireGroup = {
    op: RuleGroupOp;
    children: WireRule[];
};

export type WireRule = WireGroup | WireCondition;

/** P5-06: one segment as the list page shows it (SegmentListRow). */
export type SegmentListRow = {
    id: number;
    name: string;
    type: string;
    member_count: number;
    last_evaluated_at: string | null;
    is_active: boolean;
    is_system: boolean;
};

/** P5-06: one segment as the detail page shows it (SegmentDetail). `rule` is the raw PRD §17 tree, rendered read-only. */
export type SegmentDetail = {
    id: number;
    name: string;
    description: string | null;
    type: string;
    rule: WireRule | null;
    member_count: number;
    last_evaluated_at: string | null;
    last_eval_ms: number | null;
    is_active: boolean;
    is_system: boolean;
};

/** P5-06: one member as a segment's member list shows it (SegmentMemberRow) — phone always masked. */
export type SegmentMemberRow = {
    id: number;
    display_name: string | null;
    phone: string | null;
    status: string;
    lifecycle_stage: string;
    added_at: string;
};

/** P5-06: the create/edit form's own segment fields, as SegmentEditController pre-fills them. */
export type SegmentFormValues = {
    id: number;
    name: string;
    description: string | null;
    rule: WireRule;
    is_system: boolean;
};
