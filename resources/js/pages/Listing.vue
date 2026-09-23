<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { dateFormatter } from '@statamic/cms/api';
import {
    Badge,
    Button,
    CommandPaletteItem,
    ConfirmationModal,
    Description,
    DocsCallout,
    DropdownItem,
    EmptyStateItem,
    EmptyStateMenu,
    Header,
    Icon,
    Listing,
} from '@statamic/cms/ui';

/*
 * The five listings of this addon (partners, commissions, payouts, rates,
 * joint ventures) are this one page, fed by the controller. Core's <Listing>
 * in client mode: search, sort, column choice and saved views come with it.
 */
const props = defineProps({
    title: { type: String, required: true },
    icon: { type: String, required: true },
    preferencesPrefix: { type: String, required: true },
    rows: { type: Array, required: true },
    columns: { type: Array, required: true },
    truncated: { type: Boolean, default: false },
    total: { type: Number, default: 0 },
    locale: { type: String, default: null },
    docsUrl: { type: String, default: null },
    setupRequired: { type: Boolean, default: false },
    badges: { type: Object, default: () => ({}) },
    dates: { type: Array, default: () => [] },
    mono: { type: Array, default: () => [] },
    headerActions: { type: Array, default: () => [] },
    description: { type: String, default: null },
    emptyHeading: { type: String, default: null },
    emptyDescription: { type: String, default: null },
    emptyUrl: { type: String, default: null },
});

const firstColumn = computed(() => props.columns[0]?.field ?? null);
const badgeColumns = computed(() => Object.keys(props.badges));
const plainDates = computed(() => props.dates.filter((c) => c !== firstColumn.value));
const plainMono = computed(() => props.mono.filter((c) => c !== firstColumn.value));
const primary = computed(() => props.headerActions.find((a) => a.primary) ?? null);
const secondary = computed(() => props.headerActions.filter((a) => !a.primary));

const confirming = ref(null);
const busy = ref(false);

function reload() {
    router.reload();
}

function run(action) {
    if (!action.method || action.method === 'get') {
        window.location.href = action.url;
        return;
    }

    if (action.confirm) {
        confirming.value = action;
        return;
    }

    send(action);
}

function send(action) {
    busy.value = true;

    const options = {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            confirming.value = null;
        },
    };

    if (action.method === 'delete') {
        router.delete(action.url, options);
    } else {
        router.post(action.url, action.data ?? {}, options);
    }
}

function formatDate(value) {
    if (!value) return null;

    return props.locale
        ? dateFormatter.withLocale(props.locale, () => dateFormatter.format(value, 'date'))
        : dateFormatter.format(value, 'date');
}

function cellText(row, column) {
    const value = row[column];

    if (props.dates.includes(column)) return formatDate(value);

    return value;
}
</script>

<template>
    <Head :title="title" />

    <div class="max-w-page mx-auto">
        <template v-if="setupRequired || rows.length === 0">
            <!-- Core's empty state is a centred h1 rather than <Header>; see pages/forms/Index.vue. -->
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon :name="icon" class="size-5 text-gray-500" />{{ title }}
                </h1>
            </header>

            <EmptyStateMenu v-if="setupRequired" :heading="__('affiliates::cp.setup_heading')">
                <EmptyStateItem
                    :icon="icon"
                    :heading="__('affiliates::cp.setup_migrate_heading')"
                    :description="__('affiliates::cp.setup_migrate_description')"
                    :href="docsUrl"
                    target="_blank"
                />
            </EmptyStateMenu>

            <template v-else>
                <Description v-if="description" class="text-center mb-4" :text="description" />

                <EmptyStateMenu :heading="emptyHeading || title">
                    <EmptyStateItem
                        :icon="icon"
                        :heading="primary && emptyUrl ? primary.text : title"
                        :description="emptyDescription"
                        :href="emptyUrl || docsUrl"
                        :target="emptyUrl ? null : '_blank'"
                    />
                </EmptyStateMenu>

                <div v-if="headerActions.some((a) => a.method === 'post')" class="flex justify-center mt-4">
                    <Button
                        v-for="action in headerActions.filter((a) => a.method === 'post')"
                        :key="action.url"
                        :text="action.text"
                        @click="run(action)"
                    />
                </div>
            </template>
        </template>

        <template v-else>
            <Header :title="title" :icon="icon">
                <Button
                    v-for="action in secondary"
                    :key="action.url"
                    :text="action.text"
                    :href="!action.method || action.method === 'get' ? action.url : null"
                    @click="action.method && action.method !== 'get' ? run(action) : null"
                />

                <CommandPaletteItem
                    v-if="primary"
                    category="Actions"
                    :text="primary.text"
                    :icon="icon"
                    :url="!primary.method || primary.method === 'get' ? primary.url : null"
                    :action="primary.method && primary.method !== 'get' ? () => run(primary) : null"
                    prioritize
                    v-slot="{ text }"
                >
                    <Button
                        :text="text"
                        variant="primary"
                        :href="!primary.method || primary.method === 'get' ? primary.url : null"
                        :loading="busy"
                        @click="primary.method && primary.method !== 'get' ? run(primary) : null"
                    />
                </CommandPaletteItem>
            </Header>

            <Description v-if="description" class="mb-3" :text="description" />
            <Description v-if="truncated" class="mb-3" :text="__('affiliates::cp.truncated', { limit: rows.length, total })" />

            <Listing
                :items="rows"
                :columns="columns"
                :preferences-prefix="preferencesPrefix"
                @refreshing="reload"
            >
                <template v-if="firstColumn" #[`cell-${firstColumn}`]="{ row }">
                    <Link v-if="row.url" :href="row.url" class="whitespace-nowrap" :class="{ 'font-mono text-xs': mono.includes(firstColumn) }">
                        {{ cellText(row, firstColumn) }}
                    </Link>
                    <span v-else class="whitespace-nowrap">{{ cellText(row, firstColumn) }}</span>
                </template>

                <template v-for="column in badgeColumns" :key="`badge-${column}`" #[`cell-${column}`]="{ row }">
                    <Badge
                        pill
                        :color="badges[column][row[column]] || 'gray'"
                        :text="row[`${column}_label`] ?? row[column]"
                    />
                </template>

                <template v-for="column in plainDates" :key="`date-${column}`" #[`cell-${column}`]="{ row }">
                    <span v-if="row[column]" class="whitespace-nowrap">{{ formatDate(row[column]) }}</span>
                    <span v-else class="text-gray-500 dark:text-gray-400">&mdash;</span>
                </template>

                <template v-for="column in plainMono" :key="`mono-${column}`" #[`cell-${column}`]="{ row }">
                    <span class="font-mono text-xs">{{ row[column] }}</span>
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem
                        v-for="action in row.row_actions || []"
                        :key="action.url + action.text"
                        :text="action.text"
                        :icon="action.icon"
                        :variant="action.destructive ? 'destructive' : 'default'"
                        :href="!action.method || action.method === 'get' ? action.url : null"
                        @click="action.method && action.method !== 'get' ? run(action) : null"
                    />
                </template>
            </Listing>
        </template>

        <ConfirmationModal
            :open="confirming !== null"
            :title="confirming?.text"
            :button-text="confirming?.text"
            :cancel-text="__('affiliates::cp.cancel')"
            :busy="busy"
            :danger="!!confirming?.destructive"
            @update:open="(open) => { if (!open && !busy) confirming = null; }"
            @cancel="confirming = null"
            @confirm="send(confirming)"
        >
            <Description :text="confirming?.confirm" />
        </ConfirmationModal>

        <DocsCallout v-if="docsUrl" :topic="title" :url="docsUrl" />
    </div>
</template>
