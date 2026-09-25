import type { RuleTreeNode, RuleWhitelist } from '@/types/segments';

/**
 * Client-side mirror of RuleValidator (P5-02) — UX only. The server always re-validates the same
 * rule from scratch and is the only authority; this exists purely so a person building a rule sees
 * a mistake before clicking Preview, not after a round trip.
 */

const MESSAGES = {
    fieldRequired: 'یک فیلد انتخاب کنید',
    operatorRequired: 'یک عملگر انتخاب کنید',
    operatorNotAllowed: 'این عملگر برای این فیلد مجاز نیست',
    valueRequired: 'مقداری وارد کنید',
    valueMustBeEmpty: 'این عملگر نباید مقداری داشته باشد',
    listTooLarge: (max: number) => `حداکثر ${max} مقدار مجاز است`,
    listEmpty: 'حداقل یک مقدار وارد کنید',
    rangeNeedsTwo: 'دقیقاً دو مقدار (از و تا) لازم است',
    tooFewChildren: (min: number) => `حداقل ${min} شرط لازم است`,
    tooManyChildren: (max: number) => `حداکثر ${max} شرط مجاز است`,
    depthExceeded: (max: number) =>
        `عمق تودرتویی گروه‌ها از ${max} بیشتر شده است`,
    tooManyNodes: (max: number) => `تعداد کل گره‌ها از ${max} بیشتر شده است`,
};

export function validateRuleTree(
    tree: RuleTreeNode,
    whitelist: RuleWhitelist,
): Map<string, string> {
    const errors = new Map<string, string>();
    const nodeCount = { value: 0 };

    walk(tree, 0, whitelist, errors, nodeCount);

    return errors;
}

function walk(
    node: RuleTreeNode,
    groupDepth: number,
    whitelist: RuleWhitelist,
    errors: Map<string, string>,
    nodeCount: { value: number },
): void {
    nodeCount.value += 1;

    if (nodeCount.value > whitelist.limits.maxNodes) {
        errors.set(node.id, MESSAGES.tooManyNodes(whitelist.limits.maxNodes));

        return;
    }

    if (node.kind === 'group') {
        const newDepth = groupDepth + 1;

        if (newDepth > whitelist.limits.maxDepth) {
            errors.set(
                node.id,
                MESSAGES.depthExceeded(whitelist.limits.maxDepth),
            );

            return;
        }

        if (node.children.length < whitelist.limits.minChildren) {
            errors.set(
                node.id,
                MESSAGES.tooFewChildren(whitelist.limits.minChildren),
            );
        } else if (node.children.length > whitelist.limits.maxChildren) {
            errors.set(
                node.id,
                MESSAGES.tooManyChildren(whitelist.limits.maxChildren),
            );
        }

        for (const child of node.children) {
            walk(child, newDepth, whitelist, errors, nodeCount);
        }

        return;
    }

    validateCondition(node, whitelist, errors);
}

function validateCondition(
    node: Extract<RuleTreeNode, { kind: 'condition' }>,
    whitelist: RuleWhitelist,
    errors: Map<string, string>,
): void {
    if (node.field === null) {
        errors.set(node.id, MESSAGES.fieldRequired);

        return;
    }

    const field = whitelist.fields.find((f) => f.name === node.field);

    if (node.operator === null) {
        errors.set(node.id, MESSAGES.operatorRequired);

        return;
    }

    const operator = whitelist.operators.find((o) => o.name === node.operator);

    if (field !== undefined && operator !== undefined) {
        const fieldIsBehavior = field.group === 'behavior';

        if (fieldIsBehavior !== operator.behaviorOnly) {
            errors.set(node.id, MESSAGES.operatorNotAllowed);

            return;
        }
    }

    if (operator === undefined) {
        return;
    }

    if (operator.valueShape === 'none') {
        if (node.value !== null) {
            errors.set(node.id, MESSAGES.valueMustBeEmpty);
        }

        return;
    }

    if (node.value === null) {
        errors.set(node.id, MESSAGES.valueRequired);

        return;
    }

    if (operator.valueShape === 'list') {
        const list = Array.isArray(node.value) ? node.value : [];

        if (list.length === 0) {
            errors.set(node.id, MESSAGES.listEmpty);
        } else if (list.length > whitelist.limits.maxListValues) {
            errors.set(
                node.id,
                MESSAGES.listTooLarge(whitelist.limits.maxListValues),
            );
        }

        return;
    }

    if (operator.valueShape === 'range') {
        const range = Array.isArray(node.value) ? node.value : [];

        if (range.length !== 2) {
            errors.set(node.id, MESSAGES.rangeNeedsTwo);
        }

        return;
    }

    // scalar
    if (Array.isArray(node.value) || node.value === '') {
        errors.set(node.id, MESSAGES.valueRequired);
    }
}
