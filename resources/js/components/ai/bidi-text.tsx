import { Fragment } from 'react';

/**
 * Numbers, signed numbers, percents and Jalali dates inside right-to-left text. Each such run is wrapped in its own left-to-right isolate
 * (<bdi dir="ltr">), so a leading "-" or a trailing "%" stays attached to its number instead of jumping to the other side of the line.
 * The text itself is rendered as TEXT by React (escaped) — a model's output never reaches the DOM as HTML.
 */
const NUMBER_RUN = /([+\-−]?\d[\d,./:]*%?[+\-−]?)/g;

export function BidiText({ text }: { text: string }) {
    return (
        <>
            {text.split(NUMBER_RUN).map((part, i) =>
                i % 2 === 1 ? (
                    <bdi key={i} dir="ltr">
                        {part}
                    </bdi>
                ) : (
                    <Fragment key={i}>{part}</Fragment>
                ),
            )}
        </>
    );
}
