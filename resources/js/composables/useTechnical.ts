import { router, usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import TechnicalDetailsController from '@/actions/App/Http/Controllers/Settings/TechnicalDetailsController';

/**
 * Whether the person sees how changes are made: the plan, the code, the
 * tests and the tools for developers. Off by default, so an owner sees
 * only what their app does.
 */
export function useTechnical(): {
    technical: ComputedRef<boolean>;
    setTechnical: (on: boolean) => void;
} {
    const page = usePage();
    const technical = computed(() => !!page.props.auth.user?.technical_details);

    function setTechnical(on: boolean): void {
        router.patch(
            TechnicalDetailsController.url(),
            { technical_details: on },
            { preserveState: true, preserveScroll: true, only: ['auth'] },
        );
    }

    return { technical, setTechnical };
}
