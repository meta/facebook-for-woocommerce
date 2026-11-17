/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Event field contracts for Pixel + Conversions API (CAPI).
 *
 * This module defines field-presence contracts.
 *
 * Contract model:
 * - Base requirements are defined by generic parameter categories aligned to Meta's
 *   Conversions API model (event envelope, user data/matching keys, custom data,
 *   action/context fields, transport metadata).
 * - Pixel vs CAPI differences are represented at the category/channel level first,
 *   then event-level requirements are layered on top.
 * - Event-specific overrides should only be added when an event truly has distinct
 *   required fields (or distinct channel support). Avoid bespoke per-event shapes
 *   when the base category model already covers the requirement.
 *
 * Note:
 * - The validator currently enforces `channels`, `user_data`, and `custom_data`
 *   presence contracts. Envelope/context categories are documented here for clarity
 *   and future validator expansion.
 */

// -----------------------------------------------------------------------------
// Generic parameter categories (documentation + reusable source of truth)
// -----------------------------------------------------------------------------
const PARAMETER_CATEGORIES = {
  envelope: {
    pixel: ['event_name', 'event_id', 'pixel_id'],
    capi: ['event_name', 'event_id', 'event_time']
  },

  user_data: {
    // Keep current test expectations stable (Pixel expects `cn`; CAPI expects `country`).
    pixel: ['em', 'external_id', 'ct', 'zp', 'cn', 'fbp'],
    capi: ['em', 'external_id', 'ct', 'zp', 'country', 'fbp', 'client_ip_address', 'client_user_agent']
  },

  custom_data_base: {
    // Plugin metadata included in Pixel payloads.
    pixel: ['source', 'version', 'pluginVersion'],
    capi: []
  },

  action_context: {
    pixel: [],
    capi: ['action_source', 'event_source_url']
  },

  transport_metadata: {
    pixel: ['timestamp', 'api_status', 'api_ok', 'cookies'],
    capi: ['capturedAt']
  }
};

// -----------------------------------------------------------------------------
// Base channel contracts used by validator field checks today
// -----------------------------------------------------------------------------
const BASE_CHANNEL_CONTRACTS = {
  pixel: {
    user_data: PARAMETER_CATEGORIES.user_data.pixel,
    custom_data: PARAMETER_CATEGORIES.custom_data_base.pixel
  },
  capi: {
    user_data: PARAMETER_CATEGORIES.user_data.capi,
    custom_data: PARAMETER_CATEGORIES.custom_data_base.capi
  }
};

// -----------------------------------------------------------------------------
// Event-level overlays (only event-specific deltas)
// -----------------------------------------------------------------------------
const EVENT_OVERLAYS = {
  PageView: {
    channels: ['pixel', 'capi'],
    custom_data: {
      // PageView carries no product custom_data fields. `user_data` is a
      // top-level sibling of `custom_data` (validated via the base user_data
      // contract), not a key inside custom_data — listing it here made the
      // validator look for `custom_data.user_data`, which never exists, and
      // failed every PageView run.
      pixel: [],
      capi: []
    }
  },

  ViewContent: {
    channels: ['pixel', 'capi'],
    custom_data: {
      pixel: ['content_ids', 'content_type', 'content_name', 'value', 'currency', 'contents', 'content_category'],
      capi: ['content_ids', 'content_type', 'content_name', 'value', 'currency', 'contents', 'content_category']
    }
  },

  ViewCategory: {
    channels: ['pixel', 'capi'],
    custom_data: {
      pixel: ['content_name', 'content_category', 'content_ids', 'content_type', 'contents'],
      capi: ['content_name', 'content_category', 'content_ids', 'content_type', 'contents']
    }
  },

  AddToCart: {
    channels: ['pixel', 'capi'],
    custom_data: {
      pixel: ['content_ids', 'content_type', 'content_name', 'value', 'currency', 'contents'],
      capi: ['content_ids', 'content_type', 'content_name', 'value', 'currency', 'contents']
    }
  },

  InitiateCheckout: {
    channels: ['pixel', 'capi'],
    custom_data: {
      pixel: ['content_ids', 'content_type', 'content_name', 'num_items', 'value', 'currency', 'contents', 'content_category'],
      capi: ['content_ids', 'content_type', 'content_name', 'num_items', 'value', 'currency', 'contents', 'content_category']
    }
  },

  Purchase: {
    channels: ['pixel', 'capi'],
    // The browser Purchase hit carries the shared _fbp identifier, while checkout
    // PII is attached to the server event and is not serialized into the Pixel
    // request on the thank-you page.
    user_data: {
      pixel: ['fbp']
    },
    custom_data: {
      pixel: ['content_ids', 'content_type', 'content_name', 'value', 'currency', 'contents', 'order_id'],
      capi: ['content_ids', 'content_type', 'content_name', 'value', 'currency', 'contents', 'order_id']
    }
  },

  Search: {
    channels: ['pixel', 'capi'],
    custom_data: {
      pixel: ['content_type', 'content_ids', 'contents', 'search_string', 'value', 'currency'],
      capi: ['content_type', 'content_ids', 'contents', 'search_string', 'value', 'currency']
    }
  },

  Subscribe: {
    channels: ['pixel', 'capi'],
    custom_data: {
      pixel: ['sign_up_fee', 'value', 'currency'],
      capi: ['sign_up_fee', 'value', 'currency']
    }
  },

  Lead: {
    channels: ['pixel'],
    user_data: {
      // True event-specific override: only email matching required in current tests.
      pixel: ['em']
    },
    custom_data: {
      pixel: []
    }
  }
};

function unique(values) {
  return [...new Set(values)];
}

function buildEventContract(eventName, overlay) {
  const contract = {
    channels: overlay.channels
  };

  for (const channel of overlay.channels) {
    const base = BASE_CHANNEL_CONTRACTS[channel] || { user_data: [], custom_data: [] };

    // By default, inherit base user_data/custom_data. Overlay can add fields, and
    // may replace user_data for specific events (Lead).
    const userDataOverlay = overlay.user_data?.[channel];
    const userData = userDataOverlay
      ? unique(userDataOverlay)
      : unique(base.user_data);

    const customDataOverlay = overlay.custom_data?.[channel] || [];
    const customData = unique([...base.custom_data, ...customDataOverlay]);

    contract[channel] = {
      user_data: userData,
      custom_data: customData
    };
  }

  return contract;
}

const EVENT_FIELD_CONTRACTS = Object.fromEntries(
  Object.entries(EVENT_OVERLAYS).map(([eventName, overlay]) => [eventName, buildEventContract(eventName, overlay)])
);

module.exports = EVENT_FIELD_CONTRACTS;
