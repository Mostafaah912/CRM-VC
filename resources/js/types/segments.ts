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
