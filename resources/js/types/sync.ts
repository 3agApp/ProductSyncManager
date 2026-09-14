export type SyncRunStatus = 'queued' | 'running' | 'completed' | 'failed';

export type SyncTrigger = 'manual' | 'scheduled' | 'webhook';

export type SyncFrequency = 'hourly' | 'six_hourly' | 'daily' | 'weekly';

export type SyncRun = {
    id: number;
    status: SyncRunStatus;
    statusLabel: string;
    isActive: boolean;
    trigger: SyncTrigger;
    triggerLabel: string;
    progress: number | null;
    totalProducts: number | null;
    processedProducts: number;
    createdProducts: number;
    updatedProducts: number;
    archivedProducts: number;
    skippedProducts: number;
    message: string | null;
    durationInSeconds: number | null;
    startedAt: string | null;
    finishedAt: string | null;
    createdAtDiff: string | null;
};

export type ActiveSyncRun = {
    id: number;
    status: SyncRunStatus;
    progress: number | null;
    processedProducts: number;
    totalProducts: number | null;
};

export type WooCommerceConnectionPreview = {
    webhookUrl: string;
    webhooksConfiguredAt: string | null;
    webhookConfigured: boolean;
    storeUrl: string;
    consumerKeyPreview: string;
    consumerSecretPreview: string;
    verifiedAt: string | null;
    isUsable: boolean;
};

export type SyncSettings = {
    isConfigured: boolean;
    isEnabled: boolean;
    frequency: SyncFrequency;
    timeOfDay: string;
    dayOfWeek: number | null;
    timezone: string;
    lastRunAt: string | null;
    nextRunAt: string | null;
    nextRunAtDiff: string | null;
};

export type SyncFrequencyOption = {
    value: SyncFrequency;
    label: string;
};
