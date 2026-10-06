import {
    isValidJalaaliDate,
    jalaaliMonthLength,
    jalaaliToDateObject,
    toJalaali,
} from 'jalaali-js';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/** Same 12 names as the single source of truth, `App\Support\JalaliDate::MONTH_NAMES` — a label list,
 * not a calculation, so duplicating it here is not the "ریاضی شمسی در JS" this component avoids
 * writing: all actual Jalali arithmetic (leap years, month lengths, weekday) goes through `jalaali-js`. */
const MONTH_NAMES = [
    'فروردین',
    'اردیبهشت',
    'خرداد',
    'تیر',
    'مرداد',
    'شهریور',
    'مهر',
    'آبان',
    'آذر',
    'دی',
    'بهمن',
    'اسفند',
];

const WEEKDAY_LABELS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

type JalaliParts = { jy: number; jm: number; jd: number };

function pad2(n: number): string {
    return n < 10 ? `0${n}` : String(n);
}

function format(parts: JalaliParts): string {
    return `${parts.jy}/${pad2(parts.jm)}/${pad2(parts.jd)}`;
}

function parse(value: string | null): JalaliParts | null {
    if (!value) {
        return null;
    }

    const m = /^(\d{4})\/(\d{2})\/(\d{2})$/.exec(value.trim());

    if (!m) {
        return null;
    }

    const jy = Number(m[1]);
    const jm = Number(m[2]);
    const jd = Number(m[3]);

    return isValidJalaaliDate(jy, jm, jd) ? { jy, jm, jd } : null;
}

/** Saturday-first weekday index (0=Saturday..6=Friday) for the 1st of a Jalali month. */
function firstWeekdayOfMonth(jy: number, jm: number): number {
    const jsDay = jalaaliToDateObject(jy, jm, 1).getDay();

    return (jsDay + 1) % 7;
}

function compare(a: JalaliParts, b: JalaliParts): number {
    if (a.jy !== b.jy) return a.jy - b.jy;
    if (a.jm !== b.jm) return a.jm - b.jm;

    return a.jd - b.jd;
}

function addMonths(jy: number, jm: number, delta: number): { jy: number; jm: number } {
    const total = (jy * 12 + (jm - 1)) + delta;

    return { jy: Math.floor(total / 12), jm: (((total % 12) + 12) % 12) + 1 };
}

type Props = {
    from: string | null;
    to: string | null;
    onChange: (range: { from: string | null; to: string | null }) => void;
    className?: string;
};

/**
 * A Jalali calendar-range picker: value on the wire is always `YYYY/MM/DD` Jalali, exactly what
 * `App\Support\JalaliDay`/`JalaliDate` already parse backend-side (CLAUDE.md §2) — this component
 * never produces or consumes a Gregorian string. All calendar arithmetic (leap years, month length,
 * weekday) is delegated to `jalaali-js`, not hand-written here.
 */
export function JalaliRangePicker({ from, to, onChange, className }: Props) {
    const [open, setOpen] = useState(false);
    const fromParts = useMemo(() => parse(from), [from]);
    const toParts = useMemo(() => parse(to), [to]);
    const today = useMemo(() => {
        const g = new Date();

        return toJalaali(g.getFullYear(), g.getMonth() + 1, g.getDate());
    }, []);

    const [viewYear, setViewYear] = useState(fromParts?.jy ?? today.jy);
    const [viewMonth, setViewMonth] = useState(fromParts?.jm ?? today.jm);

    function openChange(next: boolean) {
        setOpen(next);

        if (next) {
            setViewYear(fromParts?.jy ?? today.jy);
            setViewMonth(fromParts?.jm ?? today.jm);
        }
    }

    /**
     * Driven entirely by the controlled `from`/`to` props, not extra local state: `to === null` means
     * a selection is in progress (nothing picked yet, or only the first day) — the next click completes
     * it. A complete range already showing means this click starts a fresh one, discarding the old range
     * rather than extending it (the common case, since the picker usually opens on an already-applied
     * range — starting from "the old `to` is still in progress" was a real bug caught by the Phase-1-style
     * Playwright pass: the very first click closed the popover using the stale previous range).
     */
    function pickDay(day: JalaliParts) {
        if (fromParts === null || toParts !== null) {
            onChange({ from: format(day), to: null });

            return;
        }

        if (compare(day, fromParts) < 0) {
            onChange({ from: format(day), to: format(fromParts) });
        } else {
            onChange({ from: format(fromParts), to: format(day) });
        }

        setOpen(false);
    }

    const monthLength = jalaaliMonthLength(viewYear, viewMonth);
    const leadingBlanks = firstWeekdayOfMonth(viewYear, viewMonth);
    const cells: Array<JalaliParts | null> = [
        ...Array.from({ length: leadingBlanks }, () => null),
        ...Array.from({ length: monthLength }, (_, i) => ({ jy: viewYear, jm: viewMonth, jd: i + 1 })),
    ];

    const yearOptions = Array.from({ length: 11 }, (_, i) => today.jy - 5 + i);

    return (
        <Popover open={open} onOpenChange={openChange}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={cn('font-normal', className)}
                >
                    {fromParts && toParts ? (
                        <span dir="rtl">
                            از <bdi dir="ltr">{format(fromParts)}</bdi> تا{' '}
                            <bdi dir="ltr">{format(toParts)}</bdi>
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            انتخاب بازه
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-3" align="start">
                <div className="flex items-center justify-between gap-2 pb-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="ماه قبل"
                        onClick={() => {
                            const next = addMonths(viewYear, viewMonth, -1);
                            setViewYear(next.jy);
                            setViewMonth(next.jm);
                        }}
                    >
                        ‹
                    </Button>

                    <div className="flex items-center gap-1">
                        <Select
                            value={String(viewMonth)}
                            onValueChange={(v) => setViewMonth(Number(v))}
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-28"
                                aria-label="ماه"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {MONTH_NAMES.map((name, idx) => (
                                    <SelectItem
                                        key={name}
                                        value={String(idx + 1)}
                                    >
                                        {name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={String(viewYear)}
                            onValueChange={(v) => setViewYear(Number(v))}
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-20"
                                aria-label="سال"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {yearOptions.map((y) => (
                                    <SelectItem key={y} value={String(y)}>
                                        {y}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="ماه بعد"
                        onClick={() => {
                            const next = addMonths(viewYear, viewMonth, 1);
                            setViewYear(next.jy);
                            setViewMonth(next.jm);
                        }}
                    >
                        ›
                    </Button>
                </div>

                <div
                    className="grid grid-cols-7 gap-1"
                    role="grid"
                    aria-label={`${MONTH_NAMES[viewMonth - 1]} ${viewYear}`}
                >
                    {WEEKDAY_LABELS.map((label) => (
                        <div
                            key={label}
                            className="text-muted-foreground flex h-8 w-8 items-center justify-center text-xs"
                        >
                            {label}
                        </div>
                    ))}

                    {cells.map((cell, idx) => {
                        if (cell === null) {
                            return <div key={`blank-${idx}`} />;
                        }

                        const isToday = compare(cell, today) === 0;
                        const isSelectedFrom =
                            fromParts !== null &&
                            compare(cell, fromParts) === 0;
                        const isSelectedTo =
                            toParts !== null && compare(cell, toParts) === 0;
                        const isInRange =
                            fromParts !== null &&
                            toParts !== null &&
                            compare(cell, fromParts) >= 0 &&
                            compare(cell, toParts) <= 0;

                        return (
                            <button
                                key={`${cell.jy}-${cell.jm}-${cell.jd}`}
                                type="button"
                                role="gridcell"
                                aria-label={format(cell)}
                                aria-pressed={
                                    isSelectedFrom || isSelectedTo
                                }
                                onClick={() => pickDay(cell)}
                                className={cn(
                                    'flex h-8 w-8 items-center justify-center rounded-md text-sm tabular-nums',
                                    'hover:bg-accent hover:text-accent-foreground',
                                    'focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-hidden',
                                    isInRange && 'bg-accent/50',
                                    (isSelectedFrom || isSelectedTo) &&
                                        'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground',
                                    isToday &&
                                        !isSelectedFrom &&
                                        !isSelectedTo &&
                                        'border-primary border',
                                )}
                            >
                                {cell.jd}
                            </button>
                        );
                    })}
                </div>
            </PopoverContent>
        </Popover>
    );
}

type DayProps = {
    value: string | null;
    onChange: (value: string | null) => void;
    className?: string;
};

/** The single-day counterpart of {@link JalaliRangePicker} — one click commits and closes. Same
 * Jalali-only wire format (`YYYY/MM/DD`), same shared grid helpers, no separate date math. */
export function JalaliDayPicker({ value, onChange, className }: DayProps) {
    const [open, setOpen] = useState(false);
    const parts = useMemo(() => parse(value), [value]);
    const today = useMemo(() => {
        const g = new Date();

        return toJalaali(g.getFullYear(), g.getMonth() + 1, g.getDate());
    }, []);

    const [viewYear, setViewYear] = useState(parts?.jy ?? today.jy);
    const [viewMonth, setViewMonth] = useState(parts?.jm ?? today.jm);

    function openChange(next: boolean) {
        setOpen(next);

        if (next) {
            setViewYear(parts?.jy ?? today.jy);
            setViewMonth(parts?.jm ?? today.jm);
        }
    }

    const monthLength = jalaaliMonthLength(viewYear, viewMonth);
    const leadingBlanks = firstWeekdayOfMonth(viewYear, viewMonth);
    const cells: Array<JalaliParts | null> = [
        ...Array.from({ length: leadingBlanks }, () => null),
        ...Array.from({ length: monthLength }, (_, i) => ({ jy: viewYear, jm: viewMonth, jd: i + 1 })),
    ];

    const yearOptions = Array.from({ length: 11 }, (_, i) => today.jy - 5 + i);

    return (
        <Popover open={open} onOpenChange={openChange}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={cn('font-normal', className)}
                >
                    {parts ? (
                        <bdi dir="ltr">{format(parts)}</bdi>
                    ) : (
                        <span className="text-muted-foreground">
                            انتخاب تاریخ
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-3" align="start">
                <div className="flex items-center justify-between gap-2 pb-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="ماه قبل"
                        onClick={() => {
                            const next = addMonths(viewYear, viewMonth, -1);
                            setViewYear(next.jy);
                            setViewMonth(next.jm);
                        }}
                    >
                        ‹
                    </Button>

                    <div className="flex items-center gap-1">
                        <Select
                            value={String(viewMonth)}
                            onValueChange={(v) => setViewMonth(Number(v))}
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-28"
                                aria-label="ماه"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {MONTH_NAMES.map((name, idx) => (
                                    <SelectItem
                                        key={name}
                                        value={String(idx + 1)}
                                    >
                                        {name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={String(viewYear)}
                            onValueChange={(v) => setViewYear(Number(v))}
                        >
                            <SelectTrigger
                                size="sm"
                                className="w-20"
                                aria-label="سال"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {yearOptions.map((y) => (
                                    <SelectItem key={y} value={String(y)}>
                                        {y}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="ماه بعد"
                        onClick={() => {
                            const next = addMonths(viewYear, viewMonth, 1);
                            setViewYear(next.jy);
                            setViewMonth(next.jm);
                        }}
                    >
                        ›
                    </Button>
                </div>

                <div
                    className="grid grid-cols-7 gap-1"
                    role="grid"
                    aria-label={`${MONTH_NAMES[viewMonth - 1]} ${viewYear}`}
                >
                    {WEEKDAY_LABELS.map((label) => (
                        <div
                            key={label}
                            className="text-muted-foreground flex h-8 w-8 items-center justify-center text-xs"
                        >
                            {label}
                        </div>
                    ))}

                    {cells.map((cell, idx) => {
                        if (cell === null) {
                            return <div key={`blank-${idx}`} />;
                        }

                        const isToday = compare(cell, today) === 0;
                        const isSelected =
                            parts !== null && compare(cell, parts) === 0;

                        return (
                            <button
                                key={`${cell.jy}-${cell.jm}-${cell.jd}`}
                                type="button"
                                role="gridcell"
                                aria-label={format(cell)}
                                aria-pressed={isSelected}
                                onClick={() => {
                                    onChange(format(cell));
                                    setOpen(false);
                                }}
                                className={cn(
                                    'flex h-8 w-8 items-center justify-center rounded-md text-sm tabular-nums',
                                    'hover:bg-accent hover:text-accent-foreground',
                                    'focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-hidden',
                                    isSelected &&
                                        'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground',
                                    isToday &&
                                        !isSelected &&
                                        'border-primary border',
                                )}
                            >
                                {cell.jd}
                            </button>
                        );
                    })}
                </div>
            </PopoverContent>
        </Popover>
    );
}
