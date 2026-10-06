export type FeatureRequestStatus =
    | 'generating'
    | 'generated'
    | 'answered'
    | 'failed'
    | 'cancelled';

export type ProjectSummary = {
    id: string;
    name: string;
    /** Where an app brought in came from; null for one started here. */
    source_path: string | null;
    published_at: string | null;
    /** How many of the app's own tests guard it, as last run. */
    tests: number | null;
    /** The link that lets others try the app, while it works. */
    share: { url: string; expires_at: string } | null;
    /** How many days a new link works. */
    share_days: number;
    /** The owner's own Claude Code or Codex, when it writes the changes. */
    own_tool: { connected: boolean; address: string; name: string };
};

/** Someone who can sign in to the app on show, as the app keeps them. */
export type PreviewPerson = {
    id: string;
    name: string | null;
    email: string | null;
};

/** One app on the owner's apps list. */
export type ProjectListItem = {
    id: string;
    name: string;
    published_at: string | null;
    // When the owner last asked for a change, edited the design, or made it.
    edited_at: string | null;
    waiting: number;
    // Whether the newest change is being made or stopped.
    now: 'working' | 'stopped' | null;
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
    id: string;
    /** What the owner asked, or what a background change does. */
    prompt: string;
    /** Made by the builder on its own, not asked for. */
    background: boolean;
    summary: string | null;
    state: ChangeState;
    /** Stopped by the owner, not by something going wrong. */
    stopped_by_owner: boolean;
    /** Waiting on the owner's answer to a question. */
    asks: boolean;
    /** The question it waits on, when it asks. */
    question: string | null;
    /** How many of its tests fail without it, for a change to try. */
    proved: number;
    /** When none was seen to fail without it, how many tests it added
     * pass along with every other check. */
    passing: number;
    /** Nothing of it is kept, so it can be marked as not needed. */
    dismissable: boolean;
    updated_at: string | null;
};

export type FeatureRequestSummary = {
    id: string;
    prompt: string;
    status: FeatureRequestStatus;
    created_at?: string | null;
    target_step?: string | null;
    accepted?: boolean;
};

export type ProjectTelemetry = {
    requests: number;
    kept: number;
    reverted: number;
    cost_usd: number;
    unpriced_calls: number;
    cost_per_kept_change_usd: number | null;
    runs_verified: number;
    first_attempt_passed: number;
    first_attempt_unverified: number;
    repairs_before_acceptance: number | null;
    reviewed: number;
    with_unexpected_changes: number;
    unexpected_kept: number;
    unexpected_undone: number;
    with_notes_behind: number;
    input_tokens: number;
    output_tokens: number;
    edits_without_model: number;
    setup_cost_usd: number;
    owner_actions: {
        adjustments: number;
        stops: number;
        retries: number;
        undos: number;
    };
    owner_actions_per_kept_change: number | null;
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
    id: string;
    prompt: string;
    /** What a change made in the background does, in place of the owner's
     * words; null for a change the owner asked for. */
    background: string | null;
    images: RequestImage[];
    status: FeatureRequestStatus;
    summary: string | null;
    error: string | null;
    target_step: ChangeStep | null;
    steps: ChangeStep[];
    files: ChangedFile[];
    commit_sha: string | null;
    tests_added: number;
    accepted_at: string | null;
    revert_sha: string | null;
    reverted_at: string | null;
    /** Undone, but the live app still has it: the version to put online, and how many other kept changes go with it. */
    still_online: { head: string | null; others: number } | null;
    kept_data: boolean;
    can_accept: boolean;
    can_retry: boolean;
    /** Checking or trying it again fails the same way: it can only be made again. */
    made_again_only: boolean;
    /** The cases a test was written for before the build, by criterion number (from 1) and kind. */
    written_cases: {
        criterion: number;
        kind: 'base' | 'alternate' | 'exception';
        says: string;
    }[];
    /** The owner may still say one of them is not what they meant, which makes the change again. */
    can_correct_cases: boolean;
    /** It stopped without a change to keep, so asking in other words is offered. */
    stopped: boolean;
    /** The newer try of this change, when it was tried again. */
    tried_again: string | null;
    // It stopped just as the try before it did.
    failed_same_way: boolean;
    /** It stopped only for want of tries, and can go on from its work so far. */
    can_keep_trying: boolean;
    can_continue: boolean;
    /** Whether the owner can write it with their own Claude Code or Codex. */
    can_work_yourself: boolean;
    /** An earlier change in this chat that passed and can still be kept. */
    keep_earlier: string | null;
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
    /** How the check went on the starting commit, when it failed here. */
    at_start?: VerificationOutcome;
    /** What failed here but not on the starting commit. */
    new_problems?: string[];
};

export type Verification = {
    id: string;
    status: VerificationStatus;
    results: VerificationResult[];
    error: string | null;
    /** Which checks the change made fail, in the owner's words. */
    failed: string | null;
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
    id: string;
    status: RunStatus;
    error: string | null;
    /** It stopped finding nothing to change: what it checked and why. */
    found_nothing?: string | null;
    /** It stopped because the month's AI use ran out, and it still has. */
    plan_ran_out?: boolean;
    /** What the owner can do about the stop; the page offers only that. */
    next_step?: 'retry' | 'settings' | 'answer' | 'contact' | null;
    question: {
        text: string;
        /** The few details that are hard to undo, shown under the question. */
        glance?: string[];
        /** Everything the question covers, behind "The plan". */
        details?: string[];
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
    /** What I decided for the owner that they said to keep. */
    kept_assumptions: string[];
    /** Set when the owner's own Claude Code or Codex writes the change:
     * whether it still waits for their change, and where it connects. */
    yours: {
        waiting: boolean;
        address: string;
        name: string;
        /** Their tool is connected to the whole app and picks it up. */
        whole_app: boolean;
        /** Their tool handed a change back. */
        wrote: boolean;
    } | null;
    plan: {
        summary: string;
        /** The reply when the owner only asked about the app. */
        answer: string | null;
        acceptance_criteria: string[];
        /** How each criterion is tried, numbered from 1. */
        cases: PlanCase[];
        /**
         * What was decided for the owner, already in reading order: glance
         * ones first (touch something that matters or cannot be undone),
         * then quiet ones. The server decides both.
         */
        assumptions: { text: string; level: 'glance' | 'quiet' }[];
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
        | 'thinking'
        | 'read'
        | 'changed'
        | 'tested'
        | 'tried'
        | 'noted'
        | 'stage'
        | 'passed'
        | 'failed'
        | 'known';
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

export type PlanCase = {
    criterion: number;
    kind: 'base' | 'alternate' | 'exception';
    /** What the test tries, or null when the case does not apply. */
    says: string | null;
    /** Why the case does not apply. */
    none: string | null;
};

export type RunReview = {
    summary: string;
    verified: {
        criterion: string;
        /** The case it checks. */
        kind: PlanCase['kind'];
        /** The case in the plan's words. */
        case: string;
        test_file: string | null;
        test_name: string | null;
        evidence:
            | 'tested'
            | 'not_run'
            | 'not_run_by_checks'
            | 'claimed'
            | 'no_test'
            | 'already_true'
            | 'no_request'
            | 'not_refused';
        named_in_diff: boolean;
    }[];
    changes: {
        area: string | null;
        area_name: string | null;
        section: ChangeSection;
        evidence: 'tested' | 'in_change' | 'not_in_change';
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
    // How well each touched part's tests cover it.
    coverage: {
        area: string;
        name: string;
        tests_passed: number;
        cases: Record<
            'base' | 'alternate' | 'exception',
            'tested' | 'not_tested' | 'not_needed'
        >;
    }[];
    unclaimed: string[];
    context_updates: string[];
    // Parts whose code changed but whose notes did not.
    notes_behind: { key: string; name: string }[];
    // We failed to update the notes after a worker's change.
    notes_failed: boolean;
    /** Bringing those notes up to date once the change is kept: whether it may be asked for, how it went, the parts updated and why it failed. */
    notes_update: {
        can: boolean;
        state: 'working' | 'done' | 'failed' | null;
        updated: string[];
        message: string | null;
    };
};

export type PreviewStatus = 'starting' | 'ready' | 'failed' | 'stopped';

export type Preview = {
    id: string;
    status: PreviewStatus;
    error: string | null;
    url: string;
    expires_at: string | null;
    // A copy of a change the owner can design on, as they do the app.
    editable: boolean;
    // The app changed after the change was made, so it could not be put
    // onto it: only making the change again helps.
    no_longer_fits: boolean;
    origin: string;
    revision: string | null;
    updating: boolean;
};

// A picture the owner attached to a message, to show what they mean.
export type RequestImage = { url: string; name: string };

// One plain sentence on how we know a change works.
export type ProofLine = {
    kind:
        | 'passed'
        | 'caught'
        | 'reach'
        | 'gap'
        | 'rule'
        | 'approach'
        | 'chosen'
        | 'packages';
    text: string;
    /** Pictures of a changed screen as a phone, a tablet and a computer show it. */
    pictures?: { url: string; label: string }[];
    /** Shows the change's own behaviour was tried, not only that the rest still works. */
    evidence?: boolean;
    /** Things listed under the line, such as the rules a part must keep. */
    items?: string[];
    /** What a passed line checked, in a word or two ("safety", "sign-in"). */
    topic?: string;
    /** A finding the owner may say the change makes on purpose, or take that back. With "proposal", the agent's case for keeping it, which the owner answers yes or no. */
    decision?: {
        change: string;
        finding: string;
        accepted: boolean;
        proposal?: string;
    };
};

// Everything about one change, as its page and the workspace chat show it.
export type ChangeDetail = {
    project: { id: string; name: string };
    featureRequest: FeatureRequestDetail;
    parent: { id: string; prompt: string } | null;
    earlier: {
        id: string;
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
    | 'border_style'
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
    | 'text_case'
    | 'line_clamp'
    | 'object_fit'
    | 'object_position'
    | 'aspect_ratio'
    | 'hover_text_color'
    | 'hover_background'
    | 'focus_text_color'
    | 'focus_background'
    | 'focus_border_color'
    | 'active_text_color'
    | 'active_background'
    | 'disabled_text_color'
    | 'disabled_background'
    | 'disabled_opacity'
    | 'line_height'
    | 'letter_spacing'
    | 'text_color'
    | 'border_color'
    | 'background'
    | 'fill_color'
    | 'stroke_color';

export type VisualValue = number | string;

/** How the first version of an app started here is going, until one is kept. */
export type FirstVersion = {
    change: string;
    state: 'making' | 'asking' | 'ready' | 'stopped';
    error: string | null;
    can_retry: boolean;
    /** Stopped because the month's AI use ran out. */
    plan_ran_out: boolean;
    /** Made, but the checks still run. */
    checking: boolean;
};

export type EditorPreview = {
    id: string;
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

/** What the owner can pretend is down in the app on show. */
export type AppFault =
    | 'none'
    | 'mail'
    | 'http'
    | 'file'
    | 'cache'
    | 'notification';

/** What the app on show did behind its last pages, newest first. */
export type AppHappenings = {
    fault: AppFault;
    requests: {
        id: string;
        page: string;
        status: number;
        outcome: string | null;
        did: { text: string; failed: boolean }[];
        /** How many times in a row the app did just this. */
        times: number;
    }[];
};

/** A notice the app on show left for a person inside the app, such as what its bell shows. */
export type SentNotice = {
    id: string;
    sent_at: string | null;
    to: string;
    subject: string;
    text: string;
    read: boolean;
};

/** A kind of problem the app on show ran into, counted each time. */
export type AppProblem = {
    id: string;
    words: string;
    class: string | null;
    message: string;
    place: string | null;
    trace: string[];
    /** What the owner had made fail on purpose when it happened, if anything. */
    during: Exclude<AppFault, 'none'> | null;
    count: number;
    first_at: string | null;
    last_at: string | null;
    /** New, being fixed, fixed, cleared by the owner, or back after either. */
    state: 'new' | 'fixing' | 'fixed' | 'cleared' | 'fine' | 'back';
    /** The change that fixes it, or fixed it. */
    change: string | null;
    /** The owner's last try to fix it, when that try stopped. */
    stopped: string | null;
};

/** How a part moves, from the panel's ready-made choices. */
export type MotionChoice = {
    entrance: 'none' | 'fade' | 'rise' | 'slide' | 'zoom';
    speed: 'quick' | 'normal' | 'slow';
    wait: 'none' | 'short' | 'long';
    hover: 'none' | 'lift' | 'grow';
    loop: 'none' | 'pulse' | 'bounce' | 'spin';
};

export type Motion = MotionChoice & {
    /** It moves at all: comes in, answers the pointer or keeps moving. */
    moves: boolean;
    /** It also moves in a way the choices cannot show. */
    custom: boolean;
    /** How it moves, in a sentence; null when it does not. */
    words: string | null;
    /** The choices that suit this kind of part. */
    suggested: Partial<Pick<MotionChoice, 'entrance' | 'hover'>>;
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
    /** The server action the part starts through Wayfinder, by its
     * controller and method; a key of null says why there is none. */
    behavior: {
        key: string | null;
        route: string | null;
        reason: 'not_bound' | 'not_found' | null;
    } | null;
    classes: string;
    /** How it moves, read from its classes. */
    motion: Motion;
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
        id: string;
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
    /** Whether it sits in a row, a column or a grid beside parts it can
     * change places with, so it moves from place to place there. */
    snaps?: boolean;
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
    /** How a picture fills its box, as "cover"; "fill" stretches it. */
    object_fit?: string;
    /** The widest it may get, as the browser works it out. */
    max_width: string;
    /** The size of one rem in the app, in pixels. */
    rem: number;
};

/** A colour the app's stylesheets write: the name its classes use
 * ("brand-500" in `bg-brand-500`), the variable that holds it, and
 * whether it has Tailwind classes at all. */
export type AppColor = {
    name: string;
    variable: string;
    classes: boolean;
};

// Design edits on the app that wait to be kept, and how checking them went.
export type DesignEdits = {
    edits: number;
    checking: boolean;
    problem: string | null;
};

export type VisualEditSummary = {
    id: string;
    tag: string;
    device: Device;
    /** A change to how the part looks, a move among its siblings, new
     * words, a new address for a link, a new picture, a new way to move, a
     * copy, or a removal. */
    kind:
        | 'look'
        | 'move'
        | 'text'
        | 'link'
        | 'picture'
        | 'theme'
        | 'motion'
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
    /** Which of the app's colours changed (the variable that holds it),
     * for which look, and its value before and after, for a change to the
     * app's colours. */
    theme: {
        mode: 'light' | 'dark';
        token: string;
        before: string;
        after: string;
    } | null;
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
    // Connections the owner ruled out; each can be put back.
    not_connected: { to: string; name: string }[];
    tested: boolean;
    // How many of the app's tests run this part's own code; null before any test run was mapped.
    checked_by: number | null;
    // What those tests check, in their authors' words.
    checks: string[];
    // What the owner asked for here, each proved by a test when kept; checked says whether that test is still in the app (null when unknown).
    asked_for: { text: string; checked: boolean | null }[];
    // Whether the owner asked to be extra careful with this part.
    careful: boolean;
    file: string | null;
};

export type CheckFinding = {
    title: string;
    details: string[];
    /** How the notes alone can put it right: the items to take out of one part. */
    fix?: { part: string; remove: string[] };
    // Notes that may be out of date: the owner can say they are still right.
    confirm?: { part: string };
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
    /** A copy of the app's information was saved before it went out. */
    backed_up: boolean;
    /** When the version it put back first came online, when it goes back. */
    restores: string | null;
    checks: { name: string; passed: boolean }[];
    /** Which check runs now, while it is checked before going online. */
    doing: string | null;
    error: string | null;
    /** Who puts a failure right: the owner's publishing settings, or us. */
    error_cause: 'settings' | 'ours' | null;
    /** What the host or Git said behind a failure, for Details only. */
    error_details: string | null;
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
            id: string;
            asked: string;
            data?: ('deletes' | 'renames' | 'reshapes' | 'rewrites')[];
        }[];
        undone: { id: string; asked: string }[];
        edits: number;
    } | null;
    /** The version the owner can go back to, with whether the newer one
     * changed how the app stores information. */
    previous: {
        id: number;
        at: string | null;
        stored: boolean;
        /** A copy of the information from before the newer version is kept. */
        copy: boolean;
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
        /** How many of the app's tests ran this part's code; null when they could not run. */
        tests: number | null;
        /** The pages the tests opened that ran this part's code. */
        pages: string[];
        /** Whether the app was explored for this part; older drafts were not. */
        explored: boolean;
    }[];
    error: string | null;
};

/** What exploring an app without notes costs, told before the owner starts it. */
export type Exploration = {
    tokens: number;
    cost_usd: number | null;
};

/** Something that needs the owner, such as a change that is ready to try. */
export type OwnerNotification = {
    id: string;
    kind: 'ready' | 'answered' | 'question' | 'failed';
    title: string;
    body: string;
    // Why a change did not work and what to do; null otherwise.
    reason: string | null;
    // The app it is about; null once that app is gone.
    app: string | null;
    read: boolean;
    created_at: string | null;
};

/** A change still waiting on the owner's answer, whatever its note says. */
export type WaitingQuestion = {
    id: string;
    title: string;
    body: string;
    app: string | null;
    href: string;
    created_at: string | null;
};

export type Notifications = {
    unread: number;
    items: OwnerNotification[];
    /** Waiting questions with no unread note, so not in unread. */
    waiting: WaitingQuestion[];
};

/** An idea the owner tries apart from their app, on its own branch. */
export type Idea = {
    id: string;
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
    /** The column that names each row, when rows can be deleted by it. */
    key: string | null;
    /** Each row's name in that column, in the order of the rows. */
    ids: (string | null)[];
    /** The columns whose values can be changed. */
    changeable: string[];
    /** For each row, the places of values shown cut short. */
    cut: number[][];
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
