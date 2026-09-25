import type {
    RuleConditionNode,
    RuleGroupNode,
    RuleTreeNode,
    WireCondition,
    WireGroup,
    WireRule,
} from '@/types/segments';

let nextId = 0;

/** Client-only ids for React keys and error lookup — never part of the wire format. */
function makeId(): string {
    nextId += 1;

    return `rule-node-${nextId}`;
}

export function emptyCondition(): RuleConditionNode {
    return {
        id: makeId(),
        kind: 'condition',
        field: null,
        operator: null,
        value: null,
        unit: null,
    };
}

export function emptyGroup(op: 'AND' | 'OR' = 'AND'): RuleGroupNode {
    return {
        id: makeId(),
        kind: 'group',
        op,
        children: [emptyCondition()],
    };
}

/** Replaces the node with `id` anywhere in the tree by applying `updater` to it. Returns a new tree (never mutates). */
export function updateNode(
    tree: RuleTreeNode,
    id: string,
    updater: (node: RuleTreeNode) => RuleTreeNode,
): RuleTreeNode {
    if (tree.id === id) {
        return updater(tree);
    }

    if (tree.kind === 'group') {
        return {
            ...tree,
            children: tree.children.map((child) =>
                updateNode(child, id, updater),
            ),
        };
    }

    return tree;
}

/** Removes the node with `id` anywhere in the tree. A group left with zero children keeps one empty condition (PRD §17: children 1..20). */
export function removeNode(tree: RuleTreeNode, id: string): RuleTreeNode {
    if (tree.kind !== 'group') {
        return tree;
    }

    const children = tree.children
        .filter((child) => child.id !== id)
        .map((child) => removeNode(child, id));

    return {
        ...tree,
        children: children.length > 0 ? children : [emptyCondition()],
    };
}

export function addChild(
    tree: RuleTreeNode,
    groupId: string,
    child: RuleTreeNode,
): RuleTreeNode {
    if (tree.id === groupId && tree.kind === 'group') {
        return { ...tree, children: [...tree.children, child] };
    }

    if (tree.kind === 'group') {
        return {
            ...tree,
            children: tree.children.map((c) => addChild(c, groupId, child)),
        };
    }

    return tree;
}

/** Strips client-only `id`/`kind` fields for the wire format the server's RuleValidator/RuleCompiler expect. */
export function toWireRule(node: RuleTreeNode): WireRule {
    if (node.kind === 'group') {
        const group: WireGroup = {
            op: node.op,
            children: node.children.map(toWireRule),
        };

        return group;
    }

    const condition: WireCondition = {
        field: node.field ?? '',
        operator: node.operator ?? '',
    };

    if (node.value !== null) {
        condition.value = node.value;
    }

    if (node.unit !== null) {
        condition.unit = node.unit;
    }

    return condition;
}
