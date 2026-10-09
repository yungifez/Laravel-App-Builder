<script setup lang="ts">
import { Check, Code } from '@lucide/vue';
import { DropdownMenuCheckboxItem, DropdownMenuItemIndicator } from 'reka-ui';
import { useTechnical } from '@/composables/useTechnical';

const { technical, setTechnical } = useTechnical();
</script>

<!-- The switch for technical details, laid out like every other menu item:
     icon on the left, and the check on the right only when it is on. The
     menu does not keep an empty gap for people who never turn it on. -->
<template>
    <DropdownMenuCheckboxItem
        :model-value="technical"
        data-test="technical-details"
        class="relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-hidden select-none focus:bg-accent focus:text-accent-foreground [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0"
        @update:model-value="setTechnical(!!$event)"
    >
        <Code class="text-muted-foreground" />
        Technical details
        <DropdownMenuItemIndicator class="ml-auto flex">
            <Check />
        </DropdownMenuItemIndicator>
    </DropdownMenuCheckboxItem>
</template>
