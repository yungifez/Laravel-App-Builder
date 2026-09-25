export type FeatureRequestStatus = 'generating' | 'generated' | 'failed';

export type ProjectSummary = {
    id: number;
    name: string;
    source_path: string;
};

export type FeatureRequestSummary = {
    id: number;
    prompt: string;
    status: FeatureRequestStatus;
    created_at?: string | null;
    target_step?: string | null;
};

export type ChangeStep = {
    key: string;
    kind: string;
    label: string;
    file: string;
    symbol: string;
    detail: string;
};

export type ChangedFile = {
    path: string;
    additions: number;
    deletions: number;
    diff: string;
};

export type FeatureRequestDetail = {
    id: number;
    prompt: string;
    status: FeatureRequestStatus;
    summary: string | null;
    error: string | null;
    target_step: ChangeStep | null;
    steps: ChangeStep[];
    files: ChangedFile[];
};

export type VerificationStatus =
    | 'queued'
    | 'running'
    | 'passed'
    | 'failed'
    | 'errored';

export type VerificationResult = {
    name: string;
    stage: 'apply' | 'setup' | 'checks';
    exit_code: number;
    timed_out: boolean;
    duration_ms: number;
    output: string;
};

export type Verification = {
    id: number;
    status: VerificationStatus;
    results: VerificationResult[];
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
};
