export type FeatureRequestStatus =
    | 'generating'
    | 'generated'
    | 'answered'
    | 'failed'
    | 'cancelled';

export type ProjectSummary = {
    id: number;
    name: string;
    source_path: string;
    published_at: string | null;
};

/** One app on the owner's apps list. */
export type ProjectListItem = {
    id: number;
    name: string;
    published_at: string | null;
    changed_at: string | null;
    waiting: number;
    // Kept changes the version online does not have yet.
    offline: number;
    // How many of the app's own tests guard it; null before they first ran.
    tests: number | null;
    // A picture of the app from its latest kept or waiting change.
    picture: string | null;
};

/** A look an owner can start a new app with, drawn from its own colours. */
export type DesignOption = {
    key: string;
    name: string;
    description: string;
    font: string;
    radius: string;
    colors: Partial<
        Record<
            | 'background'
            | 'foreground'
            | 'primary'
            | 'primary-foreground'
            | 'accent'
            | 'muted-foreground'
            | 'border',
            string
        >
    >;
};

export type ChangeState =
    | 'waiting'
    | 'working'
    | 'answered'
    | 'kept'
    | 'stopped'
    | 'undone'
    | 'dismissed';

/** One ask the owner made, with its follow-ups folded in. */
export type ChangeItem = {
    id: number;
    prompt: string;
    summary: string | null;
    state: ChangeState;
    /** Waiting on the owner's answer to a question. */
    asks: boolean;
    /** The question it waits on, when it asks. */
    question: string | null;
    /** Nothing of it is kept, so it can be marked as not needed. */
    dismissable: boolean;
    updated_at: string | null;
};

export type FeatureRequestSummary = {
    id: number;
    prompt: string;
    status: FeatureRequestStatus;
    created_at?: string | null;
    target_step?: string | null;
    accepted?: boolean;
};

export type ProjectTelemetry = {
    requests: number;
    accepted: number;
    reverted: number;
    cost_usd: number;
    unpriced_calls: number;
    cost_per_accepted_change_usd: number | null;
    runs_verified: number;
    first_attempt_passed: number;
    first_attempt_unverified: number;
    repairs_before_acceptance: number | null;
    reviewed: number;
    with_unexpected_changes: number;
    input_tokens: number;
    output_tokens: number;
    visual_edits: number;
    setup_cost_usd: number;
    owner_actions: {
        adjustments: number;
        stops: number;
        retries: number;
        undos: number;
    };
    owner_actions_per_accepted_change: number | null;
};

export type ProjectCommit = {
    sha: string;
    subject: string;
    author: string;
    committed_at: string;
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
    images: RequestImage[];
    status: FeatureRequestStatus;
    summary: string | null;
    error: string | null;
    target_step: ChangeStep | null;
    steps: ChangeStep[];
    files: ChangedFile[];
    commit_sha: string | null;
    accepted_at: string | null;
    revert_sha: string | null;
    reverted_at: string | null;
    can_accept: boolean;
    can_retry: boolean;
    can_continue: boolean;
};

export type VerificationStatus =
    | 'queued'
    | 'running'
    | 'passed'
    | 'failed'
    | 'errored'
    | 'unverified';

export type VerificationOutcome =
    | 'passed'
    | 'failed'
    | 'errored'
    | 'skipped'
    | 'not_applicable';

export type VerificationResult = {
    name: string;
    stage: 'apply' | 'setup' | 'checks' | 'acceptance';
    outcome: VerificationOutcome;
    exit_code: number | null;
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

export type RunStatus =
    | 'queued'
    | 'planning'
    | 'implementing'
    | 'verifying'
    | 'reviewing'
    | 'completed'
    | 'needs_user_decision'
    | 'cancelling'
    | 'cancelled'
    | 'failed';

export type Run = {
    id: number;
    status: RunStatus;
    error: string | null;
    question: {
        text: string;
        why: string;
        options: string[];
        recommended: string | null;
        /** Whether the owner could switch options later without loss; unset when not judged. */
        reversible?: boolean;
    } | null;
    answers: {
        question: string;
        answer: string;
        decided_by: 'owner' | 'builder';
    }[];
    plan: {
        summary: string;
        /** The reply when the owner only asked about the app. */
        answer: string | null;
        acceptance_criteria: string[];
        assumptions: string[];
        understood_as: string | null;
        current_behavior: string | null;
        preserve: string[];
        /** What the owner might ask for next, sent with one tap. */
        next: string[];
        /** How the change serves the goal the owner wrote down, if it does. */
        goal: string | null;
    } | null;
    review: RunReview | null;
    /** What the change is doing right now, while it is being made. */
    progress: { text: string; changed: number } | null;
    /** How the change was made, step by step, in the owner's words. */
    work: WorkStep[];
    started_at: string | null;
    finished_at: string | null;
    /** What happened, in the owner's words; how it was done stays with us. */
    log: RunLogEntry[];
};

export type WorkStep = {
    kind:
        | 'thought'
        | 'read'
        | 'changed'
        | 'tested'
        | 'tried'
        | 'noted'
        | 'stage';
    text: string;
};

export type RunLogEntry = {
    sequence: number;
    text: string;
    created_at: string | null;
};

export type ChangeSection =
    | 'requested'
    | 'may_also_affect'
    | 'unexpected'
    | 'other';

export type ChangedArea = { key: string; name: string; files: string[] };

export type RunReview = {
    summary: string;
    verified: {
        criterion: string;
        test_file: string | null;
        test_name: string | null;
        evidence:
            | 'tested'
            | 'not_run'
            | 'not_run_by_checks'
            | 'claimed'
            | 'no_test';
        named_in_diff: boolean;
    }[];
    changes: {
        area: string | null;
        area_name: string | null;
        section: ChangeSection;
        behavior: string;
        before: string;
        now: string;
    }[];
    areas: Record<Exclude<ChangeSection, 'other'>, ChangedArea[]>;
    preserved: {
        area: string | null;
        area_name: string | null;
        statement: string;
        evidence: 'verified' | 'untouched' | 'not_checked';
        unchanged: boolean;
        tests: number;
    }[];
    unclaimed: string[];
    context_updates: string[];
};

export type PreviewStatus = 'starting' | 'ready' | 'failed' | 'stopped';

export type Preview = {
    id: number;
    status: PreviewStatus;
    error: string | null;
    url: string;
    expires_at: string | null;
};

// A picture the owner attached to a message, to show what they mean.
export type RequestImage = { url: string; name: string };

// One plain sentence on how we know a change works.
export type ProofLine = {
    kind: 'passed' | 'caught' | 'reach' | 'gap' | 'rule' | 'approach';
    text: string;
    /** Pictures of a changed screen as a phone, a tablet and a computer show it. */
    pictures?: { url: string; label: string }[];
    /** Shows the change's own behaviour was tried, not only that the rest still works. */
    evidence?: boolean;
    /** Things listed under the line, such as the rules a part must keep. */
    items?: string[];
};

// Everything about one change, as its page and the workspace chat show it.
export type ChangeDetail = {
    project: { id: number; name: string };
    featureRequest: FeatureRequestDetail;
    parent: { id: number; prompt: string } | null;
    earlier: {
        id: number;
        prompt: string;
        images: RequestImage[];
        summary: string | null;
        status: FeatureRequestStatus;
    }[];
    followUps: FeatureRequestSummary[];
    verification: Verification | null;
    proof: ProofLine[];
    run: Run | null;
    preview: Preview | null;
};

export type Device = 'base' | 'md' | 'lg';

export type VisualProperty =
    | 'layout'
    | 'direction'
    | 'wrap'
    | 'align'
    | 'justify'
    | 'columns'
    | 'gap'
    | 'width'
    | 'height'
    | 'max_width'
    | 'padding_x'
    | 'padding_y'
    | 'margin_x'
    | 'margin_y'
    | 'border'
    | 'radius'
    | 'shadow'
    | 'rotate'
    | 'translate_x'
    | 'translate_y'
    | 'opacity'
    | 'text_size'
    | 'text_weight'
    | 'text_align'
    | 'font_style'
    | 'text_decoration'
    | 'line_height'
    | 'letter_spacing'
    | 'text_color'
    | 'border_color'
    | 'background';

export type VisualValue = number | string;

export type EditorPreview = {
    id: number;
    status: PreviewStatus;
    error: string | null;
    origin: string;
    revision: string | null;
    updating: boolean;
};

/** An email the app on show sent. A preview keeps it instead of sending it. */
export type SentEmail = {
    id: string;
    sent_at: string | null;
    from: string;
    to: string;
    subject: string;
    html: string | null;
    text: string | null;
};

/** A kind of problem the app on show ran into, counted each time. */
export type AppProblem = {
    id: string;
    words: string;
    class: string | null;
    message: string;
    place: string | null;
    trace: string[];
    count: number;
    first_at: string | null;
    last_at: string | null;
    /** New, being fixed, fixed, cleared by the owner, or back after either. */
    state: 'new' | 'fixing' | 'fixed' | 'cleared' | 'back';
    /** The change that fixes it, or fixed it. */
    change: number | null;
};

export type InspectedElement = {
    target: string;
    file: string;
    line: number;
    tag: string | null;
    instance: boolean;
    shared: { name: string; uses: number } | null;
    editable: boolean;
    reason: 'updating' | 'behind' | 'not_found' | 'dynamic' | null;
    /** Where a link goes: null for a part that is no link, and an href of
     * null when the app decides it. */
    link: { href: string | null } | null;
    /** Which file a picture shows: null for a part that is no picture, and
     * a src of null when the app decides it. */
    picture: { src: string | null } | null;
    classes: string;
    values: Record<
        Device,
        Partial<Record<VisualProperty, { value: VisualValue; from: Device }>>
    >;
    area: {
        key: string;
        name: string;
        summary: string | null;
        rules: string[];
        behaviors: string[];
        affects: { name: string; tested: boolean }[];
    } | null;
    origin: {
        id: number;
        how: 'added' | 'changed';
        asked: string;
        at: string | null;
        decided: { question: string; answer: string } | null;
    } | null;
    revision: string;
};

/** A part of the page on show, as the parts list names it. */
export type PagePart = {
    /** How many parts it is inside. */
    depth: number;
    /** What kind of part it is, in plain words, as "Link". */
    kind: string;
    /** The words it shows, when they are all it holds. */
    words: string;
    selected: boolean;
};

export type SelectedElement = {
    source: string | null;
    instance: string | null;
    tag: string;
    text: string;
    width: number;
    height?: number;
    /** The words the part shows, when they are all it holds. */
    words?: string | null;
    /** Where the part's link goes as the app draws it, or null. */
    href?: string | null;
    /** What kind of part it is, in plain words, as "Picture". */
    kind?: string;
    /** Its own words, as the parts list names it; empty for a part that
     * only holds other parts. */
    name?: string;
    /** The picture it shows as the app draws it, or null. */
    src?: string | null;
    /** How many parts it holds, and whether it holds any words. */
    holds?: { parts: number; words: boolean };
    /** The parts it sits in, nearest first, as the parts list names them. */
    trail?: { kind: string; words: string }[];
    /** The colours the part is drawn in now, by colour property. */
    colors?: Partial<Record<VisualProperty, string>>;
    /** The sizes the part is drawn at now, so a slider starts there. */
    drawn?: Drawn;
    /** How many the page draws from the same place, this one included:
     * by where the part is written, and by where this one is used. */
    copies?: { source: number; instance: number };
    /** Whether it is drawn once for each item of a list. */
    loop?: boolean;
    /** Whether it shows only at times: on its own ("if"), in turn with
     * something else ("either"), or hidden at times ("show"). */
    when?: 'if' | 'either' | 'show' | null;
    /** The tag of what the owner clicked inside it that code draws, not
     * the app's templates, as "canvas"; null when there is none. */
    drawnBy?: string | null;
    /** The files the page is drawn from, nearest first, where words shown
     * through "{{ }}" may be written. */
    places?: string[];
};

/** How a part is drawn now, as the preview measures it. */
export type Drawn = {
    /** The size of its words, in pixels. */
    text_size: number;
    /** The space its lines take, as a share of the words' size; null for
     * the font's own spacing. */
    line_height?: number | null;
    /** The space between its letters, in ems. */
    letter_spacing?: number | null;
    /** Whether its words are slanted, as "italic". */
    font_style?: string;
    /** The lines drawn on its words, as "underline". */
    text_decoration?: string;
    /** The widest it may get, as the browser works it out. */
    max_width: string;
    /** The size of one rem in the app, in pixels. */
    rem: number;
};

export type VisualEditSummary = {
    id: number;
    tag: string;
    device: Device;
    /** A change to how the part looks, a move among its siblings, new
     * words, a new address for a link, a new picture, a copy, or a removal. */
    kind:
        | 'look'
        | 'move'
        | 'text'
        | 'link'
        | 'picture'
        | 'duplicate'
        | 'add'
        | 'remove';
    properties: VisualProperty[];
    /** The new words and the words they replaced, for new words. */
    words: string | null;
    words_before: string | null;
    /** Where a link goes after a new address. */
    link: string | null;
    /** Which file a picture shows after a new picture, and before it. */
    picture: string | null;
    picture_before: string | null;
    /** The part's classes and the app's version after this edit (or its undo). */
    classes: string;
    revision: string;
    /** The app's version before this edit, and right after it. */
    base: string | null;
    commit: string | null;
    /** Where a removed part was written. */
    removed: string | null;
    /** Where the part is written. */
    target: string;
    /** How the part looks before and after a change to its look. */
    sides: Record<
        'before' | 'after',
        {
            classes: string;
            values: Partial<Record<VisualProperty, VisualValue>>;
        }
    > | null;
    created_at: string | null;
    reverted_at: string | null;
};

export type NotesSection = {
    heading: string;
    body: string;
};

export type UnderstandingArea = {
    key: string;
    name: string;
    summary: string | null;
    behaviors: string[];
    rules: string[];
    connections: {
        to: string;
        name: string;
        reason: string;
        strength: 'strong' | 'possible' | 'historical';
    }[];
    tested: boolean;
    // How many of the app's tests run this part's own code; null before any test run was mapped.
    checked_by: number | null;
    // What those tests check, in their authors' words.
    checks: string[];
    // What the owner asked for here, each proved by a test when kept; checked says whether that test is still in the app (null when unknown).
    asked_for: { text: string; checked: boolean | null }[];
    file: string | null;
};

export type CheckFinding = {
    title: string;
    details: string[];
};

export type DeploymentSummary = {
    id: number;
    status:
        | 'checking'
        | 'pushing'
        | 'confirming'
        | 'sent'
        | 'published'
        | 'needs_attention'
        | 'failed';
    commit: string;
    checks: { name: string; passed: boolean }[];
    error: string | null;
    health: {
        path: string;
        status: number | null;
        passed: boolean;
        key?: string;
    }[];
    problems: number;
    pushed_at: string | null;
    confirmed_at: string | null;
    created_at: string | null;
    finished_at: string | null;
};

export type ProjectPublishing = {
    connected: boolean;
    managed: boolean;
    target: string | null;
    branch: string | null;
    address: string | null;
    head: string | null;
    unpublished: {
        // data: how the change would touch information the live app keeps.
        added: {
            id: number;
            asked: string;
            data?: ('deletes' | 'renames' | 'reshapes' | 'rewrites')[];
        }[];
        undone: { id: number; asked: string }[];
        edits: number;
    } | null;
    deployments: DeploymentSummary[];
};

export type NotesDraft = {
    status: 'drafting' | 'ready' | 'failed';
    purpose: string | null;
    areas: {
        key: string;
        name: string;
        summary: string;
        behaviors: string[];
        rules: string[];
    }[];
    error: string | null;
};

/** Something that needs the owner, such as a change that is ready to try. */
export type OwnerNotification = {
    id: string;
    kind: 'ready' | 'answered' | 'question' | 'failed';
    title: string;
    body: string;
    project_id: number;
    feature_request_id: number;
    url: string;
    read: boolean;
    created_at: string | null;
};

export type Notifications = {
    unread: number;
    items: OwnerNotification[];
};

/** An idea the owner tries apart from their app, on its own branch. */
export type Idea = {
    id: number;
    name: string;
    branch: string;
};

export type Ideas = {
    /** The idea the owner is working in; null is the app itself. */
    current: Idea | null;
    open: Idea[];
    /** The main branch's name, shown to power users. */
    main: string;
};

/** An outside service the app can be connected to, such as payments. */
export type AppService = {
    key: string;
    name: string;
    provider: string;
    about: string;
    /** Where the owner gets the keys. */
    keys_at: string;
    fields: {
        name: string;
        label: string;
        hint: string | null;
        secret: boolean;
    }[];
    connected: boolean;
};

/** A ready-made idea to start a new app from. */
export type Starter = {
    key: string;
    name: string;
    purpose: string;
    /** The look it starts with, by key. */
    design: string | null;
    /** What the first version includes, in the owner's words. */
    includes: string[];
};

/** A table the app on show keeps its data in, and how many rows it holds. */
export type SavedTable = {
    name: string;
    /** The table's name in plain words. */
    words: string;
    rows: number | null;
    /** False for the tables Laravel keeps for its own work. */
    own: boolean;
};

/** The newest rows of one table of the app on show, as short text. */
export type SavedRows = {
    name: string;
    words: string;
    columns: string[];
    rows: (string | null)[][];
    /** True when the table holds more rows than are shown. */
    more: boolean;
};

/** A task the app on show runs on its own, and when. */
/** A page of the app a visitor can open by its address. */
export type AppPage = {
    path: string;
    words: string;
    /** Whether a visitor must be signed in to see it. */
    signed_in: boolean;
};

export type ScheduledTask = {
    /** The name the task is run by. */
    name: string;
    words: string;
    /** When it runs, in plain words. */
    when: string;
    next: string | null;
    expression: string;
    command: string;
};

/** A file the app on show stored, such as an upload. */
export type StoredFile = {
    /** The path from the app's storage folder. */
    path: string;
    name: string;
    folder: string;
    size: number;
    stored_at: string;
    /** True for pictures the builder may show. */
    picture: boolean;
};
