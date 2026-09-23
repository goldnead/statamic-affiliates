import { inertia } from '@statamic/cms/api';

import Listing from './pages/Listing.vue';
import PartnerShow from './pages/Partners/Show.vue';

/*
 * The `affiliates::` prefix keeps these pages out of core's names and every
 * other addon's. Registered inside Statamic.booting, because `inertia`
 * belongs to the CP runtime this bundle is externalised against.
 */
Statamic.booting(() => {
    inertia.register('affiliates::Listing', Listing);
    inertia.register('affiliates::Partners/Show', PartnerShow);
});
