import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <g
                fill="none"
                stroke="currentColor"
                strokeLinecap="round"
                strokeLinejoin="round"
            >
                <g strokeWidth={2}>
                    <path d="M3 12a9 9 0 0 1 15.36-6.36L21 8" />
                    <path d="M21 3v5h-5" />
                    <path d="M21 12a9 9 0 0 1-15.36 6.36L3 16" />
                    <path d="M8 16H3v5" />
                </g>
                <g strokeWidth={1.75}>
                    <path d="m12 7.75 3.75 2.125v4.25L12 16.25l-3.75-2.125v-4.25z" />
                    <path d="m8.25 9.875 3.75 2.125 3.75-2.125M12 12v4.25" />
                </g>
            </g>
        </svg>
    );
}
