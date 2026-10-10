<script setup lang="ts">
import { Filter, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useT } from '@/composables/useT';

defineProps<{
    // How many filters are applied; shown on the button.
    active: number;
    description?: string;
}>();

defineEmits<{ reset: [] }>();

const { t } = useT();
</script>

<template>
    <Sheet>
        <SheetTrigger as-child>
            <Button
                variant="outline"
                size="icon"
                class="relative"
                :aria-label="t('Filters')"
                data-test="filter-button"
            >
                <Filter class="size-4" />
                <span
                    v-if="active > 0"
                    class="absolute -top-1.5 -right-1.5 flex size-4.5 items-center justify-center rounded-full bg-primary text-[0.65rem] font-semibold text-primary-foreground"
                >
                    {{ active }}
                </span>
            </Button>
        </SheetTrigger>
        <SheetContent side="right" class="w-80 max-w-full">
            <SheetHeader>
                <SheetTitle>{{ t('Filters') }}</SheetTitle>
                <SheetDescription v-if="description">
                    {{ description }}
                </SheetDescription>
                <SheetDescription v-else class="sr-only">
                    {{ t('Filters') }}
                </SheetDescription>
            </SheetHeader>
            <div class="flex flex-1 flex-col gap-6 overflow-y-auto px-4">
                <slot />
            </div>
            <SheetFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="active === 0"
                    @click="$emit('reset')"
                >
                    <X class="size-4" />
                    {{ t('Reset') }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
