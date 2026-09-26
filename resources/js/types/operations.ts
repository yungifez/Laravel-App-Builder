export type AttentionRecord = {
    label: string;
    detail: string | null;
    at: string | null;
    href: string | null;
};

export type AttentionItem = {
    key: string;
    title: string;
    count: number;
    href: string | null;
    records: AttentionRecord[];
};

export type QueueHealth = {
    queue: string;
    backlog: number | null;
    oldest_wait_seconds: number | null;
    alive: number;
    heard_from: boolean;
    attention: boolean;
    workers: {
        worker: string;
        queues: string;
        last_seen_at: string;
        job: string | null;
        job_started_at: string | null;
        alive: boolean;
    }[];
};

export type Spend = {
    calls: number;
    unpriced_calls: number;
    reported_usd: number;
    estimated_usd: number;
    total_usd: number;
    input_tokens: number;
    output_tokens: number;
    decision_calls: number;
    setup_calls: number;
    setup_usd: number;
    undated_setup_calls: number;
    unmetered_decision_calls: number;
    completeness: 'none' | 'complete' | 'partial';
};

export type Attention = {
    since: string;
    days: number;
    workers: QueueHealth[];
    items: AttentionItem[];
    failures: {
        stage: string;
        reason: string;
        count: number;
        href: string | null;
    }[];
    rebuilds: {
        edits: number;
        measured: number;
        median_seconds: number | null;
        p90_seconds: number | null;
        max_seconds: number | null;
        slow: number;
        not_seen: number;
    };
    waiting_on_owner: number;
    spend: Spend;
};

export type ChangeRow = {
    id: number;
    project: { id: number; name: string };
    created_at: string | null;
    outcome: string;
    completed: boolean;
    verification: string | null;
    pushed: boolean;
    published: boolean;
    driver: string | null;
    models: string[];
    calls: number;
    unpriced_calls: number;
    cost_usd: number;
    repairs: number | null;
    stop_reason: string | null;
    elapsed_seconds: number | null;
};

export type ChangeFilters = {
    project?: number;
    from?: string;
    to?: string;
    outcome?: string;
    verification?: string;
    driver?: string;
    provider?: string;
    model?: string;
    reason?: string;
};

export type ChangeFilterOptions = {
    projects: { id: number; name: string }[];
    drivers: string[];
    providers: string[];
    models: string[];
    reasons: string[];
    outcomes: { value: string; label: string }[];
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type TimeSplit = {
    queue?: number;
    machine?: number;
    owner?: number;
};

export type HistoryVerification = {
    id: number;
    status: string;
    meaning: string;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    wait_seconds: number | null;
    seconds: number | null;
    error: string | null;
    results: {
        name: string;
        stage: string;
        outcome: string;
        exit_code: number | null;
        timed_out: boolean;
        duration_ms: number;
        output: string | null;
    }[];
};

export type HistoryRun = {
    id: number;
    attempt: number;
    driver: string;
    config_version: string | null;
    status: string;
    stop_reason: string | null;
    error: string | null;
    repairs: number;
    created_at: string | null;
    finished_at: string | null;
    time: {
        queue_seconds: number;
        machine_seconds: number;
        owner_seconds: number;
    };
    segments: {
        status: string;
        started_at: string;
        seconds: number;
        split: TimeSplit;
    }[];
    events: {
        sequence: number;
        type: string;
        at: string | null;
        summary: string;
    }[];
    operations: { tool: string; count: number; failed: number }[];
    model_calls: {
        role: string | null;
        provider: string | null;
        model: string | null;
        input_tokens: number;
        output_tokens: number;
        cost_usd: number | null;
        cost_source: string | null;
        status: string | null;
        error_kind: string | null;
        at: string | null;
    }[];
    verifications: HistoryVerification[];
    failed_commands: {
        command: string;
        exit_code: number;
        timed_out: boolean;
        duration_ms: number;
        at: string | null;
        output: string;
    }[];
};

export type ChangeHistory = {
    change: {
        id: number;
        project: { id: number; name: string };
        owner: string;
        request: string;
        target_step: string | null;
        generator: string;
        status: string;
        outcome: string;
        outcome_label: string;
        error: string | null;
        created_at: string | null;
        base_revision: string | null;
        patch: { sha256: string; bytes: number; files: number } | null;
        commit_sha: string | null;
        accepted_at: string | null;
        revert_sha: string | null;
        reverted_at: string | null;
    };
    milestones: {
        completed: boolean;
        verification: string | null;
        kept: boolean;
        reverted: boolean;
        pushed: boolean;
        published: boolean;
        healthy: null;
    };
    related: {
        parent: number | null;
        retry_of: number | null;
        retries: number[];
        follow_ups: number[];
    };
    time: {
        queue_seconds: number;
        machine_seconds: number;
        owner_seconds: number;
        total_seconds: number | null;
        until_kept_seconds: number | null;
    };
    decisions: {
        name: string;
        choice: string;
        confidence: number;
        acted: boolean;
        model: string | null;
        latency_ms: number;
    }[];
    runs: HistoryRun[];
    verifications: HistoryVerification[];
    previews: {
        id: number;
        status: string;
        created_at: string | null;
        ready_at: string | null;
        start_seconds: number | null;
        stopped_at: string | null;
        error: string | null;
    }[];
    deployments: {
        id: number;
        status: string;
        commit_sha: string;
        created_at: string | null;
        pushed_at: string | null;
        confirmed_at: string | null;
        finished_at: string | null;
        error: string | null;
    }[];
};
