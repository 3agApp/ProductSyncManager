import { useEffect, useState } from "react";
import { cn } from "@/lib/utils";

export type SectionDefinition = {
    /** The id of the section the link jumps to. */
    id: string;
    label: string;
    /** Shown after the label, for a count or a marker. */
    badge?: React.ReactNode;
};

type Props = {
    sections: SectionDefinition[];
    /** Distinguishes the test hooks when a page carries more than one nav. */
    idPrefix: string;
    /**
     * Vertical beside a page, or horizontal above one where there is no
     * room for a column, such as inside the settings panel.
     */
    orientation?: "vertical" | "horizontal";
    className?: string;
};

/**
 * A list of jump links beside a long page.
 *
 * The page it navigates is one scroll -- every section is mounted and
 * visible -- so this only moves the viewport. Nothing is hidden behind it,
 * which is the point: a person who scrolls past the links still reads the
 * whole form, and a browser's find-in-page still finds every field.
 *
 * The link for the section currently under the top of the viewport is
 * marked, so the list doubles as a position indicator.
 */
export function SectionNav({
    sections,
    idPrefix,
    orientation = "vertical",
    className,
}: Props) {
    const [active, setActive] = useState(sections[0]?.id ?? "");

    /**
     * Joined rather than passed as the array, because the caller builds a
     * fresh one (badges and all) on every render and the observer should
     * only be rebuilt when the sections themselves change.
     */
    const sectionIds = sections.map((section) => section.id).join(" ");

    useEffect(() => {
        const ids = sectionIds.split(" ");

        const elements = ids
            .map((id) => document.getElementById(id))
            .filter((element) => element !== null);

        if (elements.length === 0) {
            return;
        }

        const onScreen = new Set<string>();

        /**
         * The bottom margin keeps the last section from claiming the mark
         * the moment a sliver of it appears: a section counts as current
         * once it reaches the top half of the viewport.
         */
        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        onScreen.add(entry.target.id);
                    } else {
                        onScreen.delete(entry.target.id);
                    }
                }

                const current = ids.find((id) => onScreen.has(id));

                if (current !== undefined) {
                    setActive(current);
                }
            },
            { rootMargin: "0px 0px -55% 0px" },
        );

        for (const element of elements) {
            observer.observe(element);
        }

        return () => observer.disconnect();
    }, [sectionIds]);

    /**
     * Scrolled rather than followed, so the address bar does not collect a
     * hash for every heading the person glanced at. Focus moves with the
     * viewport, or a keyboard would carry on from the link it just left.
     */
    const jump = (event: React.MouseEvent, id: string) => {
        const target = document.getElementById(id);

        if (target === null) {
            return;
        }

        event.preventDefault();
        setActive(id);

        const reducedMotion = window.matchMedia(
            "(prefers-reduced-motion: reduce)",
        ).matches;

        target.scrollIntoView({
            behavior: reducedMotion ? "auto" : "smooth",
            block: "start",
        });

        target.focus({ preventScroll: true });
    };

    return (
        <nav
            aria-label="Sections"
            data-test={`${idPrefix}-section-nav`}
            className={cn("workspace-panel p-2", className)}
        >
            <ul
                className={cn(
                    orientation === "horizontal"
                        ? "flex gap-0.5 overflow-x-auto"
                        : "grid gap-0.5",
                )}
            >
                {sections.map((section) => {
                    const isActive = section.id === active;

                    return (
                        <li
                            key={section.id}
                            className={cn(
                                orientation === "horizontal" && "shrink-0",
                            )}
                        >
                            <a
                                href={`#${section.id}`}
                                data-test={`${idPrefix}-jump-${section.id}`}
                                aria-current={isActive ? "true" : undefined}
                                onClick={(event) => jump(event, section.id)}
                                className={cn(
                                    "focus-visible:ring-ring flex items-center justify-between gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors outline-none focus-visible:ring-2",
                                    isActive
                                        ? "bg-muted text-foreground"
                                        : "text-muted-foreground hover:bg-muted/60 hover:text-foreground",
                                )}
                            >
                                {section.label}
                                {section.badge}
                            </a>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/**
 * A small count or marker beside a section link.
 */
export function SectionBadge({
    children,
    tone = "muted",
    testId,
}: {
    children: React.ReactNode;
    tone?: "muted" | "attention";
    testId?: string;
}) {
    return (
        <span
            data-test={testId}
            className={cn(
                "inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-xs tabular-nums",
                tone === "attention"
                    ? "bg-amber-500/15 text-amber-700 dark:text-amber-400"
                    : "bg-muted-foreground/15 text-muted-foreground",
            )}
        >
            {children}
        </span>
    );
}
