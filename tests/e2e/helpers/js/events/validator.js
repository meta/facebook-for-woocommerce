/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * EventValidator - Load and validate captured events
 */

const fs = require('fs').promises;
const path = require('path');
const EVENT_FIELD_CONTRACTS = require('./field-contracts');

class EventValidator {
  constructor(testId, fbc = false, expectZeroEvents = false, options = {}) {
    this.testId = testId;
    this.filePath = path.join(__dirname, '../../captured-events', `${testId}.json`);
    this.events = null;
    this.fbc = fbc;
    this.expectZeroEvents = expectZeroEvents;
    this.allowBraveFbcNormalization = Boolean(options.allowBraveFbcNormalization);
  }

  async load() {
    const pixelFilePath = path.join(__dirname, '../../captured-events', `pixel-${this.testId}.json`);
    const capiFilePath = path.join(__dirname, '../../captured-events', `capi-${this.testId}.json`);

    let pixelEvents = [];
    let capiEvents = [];

    try {
      const pixelData = await fs.readFile(pixelFilePath, 'utf8');
      pixelEvents = JSON.parse(pixelData);
      console.log(`✅ Loaded pixel events from: ${pixelFilePath}`);
    } catch (err) {
      if (err.code === 'ENOENT') {
        console.log(`⚠️  Pixel events file not found: ${pixelFilePath}`);
      } else {
        console.error(`❌ Error reading pixel events: ${err.message}`);
      }
    }

    try {
      const capiData = await fs.readFile(capiFilePath, 'utf8');
      capiEvents = JSON.parse(capiData);
      console.log(`✅ Loaded CAPI events from: ${capiFilePath}`);
    } catch (err) {
      if (err.code === 'ENOENT') {
        console.log(`⚠️  CAPI events file not found: ${capiFilePath}`);
      } else {
        console.error(`❌ Error reading CAPI events: ${err.message}`);
      }
    }

    this.events = {
      testId: this.testId,
      pixel: pixelEvents,
      capi: capiEvents
    };

    return this.events;
  }

  async validate(eventName, page = null) {
    if (!this.events) await this.load();

    console.log(`\n  🔍 Validating ${eventName}...`);

    const fieldContract = EVENT_FIELD_CONTRACTS[eventName];
    if (!fieldContract) throw new Error(`No field contract for: ${eventName}`);

    let pixel = this.events.pixel.filter(e => e.event_name === eventName);
    let capi = this.events.capi.filter(e => e.event_name === eventName);

    // CAPI logging can lag slightly behind Pixel capture on fast paths (especially PageView).
    // Poll briefly for expected channels to avoid flaky false negatives.
    if (!this.expectZeroEvents) {
      const needsPixel = fieldContract.channels.includes('pixel');
      const needsCapi = fieldContract.channels.includes('capi');
      const deadline = Date.now() + 10000;

      while (Date.now() < deadline) {
        const pixelReady = !needsPixel || pixel.length >= 1;
        const capiReady = !needsCapi || capi.length >= 1;

        if (pixelReady && capiReady) {
          break;
        }

        await new Promise(resolve => setTimeout(resolve, 250));
        await this.load();
        pixel = this.events.pixel.filter(e => e.event_name === eventName);
        capi = this.events.capi.filter(e => e.event_name === eventName);
      }
    }

    console.log(`   Pixel events found: ${pixel.length}`);
    console.log(`   CAPI events found: ${capi.length}`);

    const errors = [];
    const countCheckResult = this.validateEventCounts(pixel, capi, eventName, errors);
    if (!countCheckResult.passed) {
      return countCheckResult;
    }

    const p = pixel[0] || null;
    const c = capi[0] || null;
    const hasPixel = fieldContract.channels.includes('pixel');
    const hasCapi = fieldContract.channels.includes('capi');

    if (hasPixel && p) {
      this.validateFieldsExistence(eventName, 'pixel', 'user_data', p, errors);
      this.validateFieldsExistence(eventName, 'pixel', 'custom_data', p, errors);
    }
    if (hasCapi && c) {
      this.validateFieldsExistence(eventName, 'capi', 'user_data', c, errors);
      this.validateFieldsExistence(eventName, 'capi', 'custom_data', c, errors);
    }

    console.log(`  ✓ Running data validators...`);

    if (hasPixel && hasCapi && p && c) {
      this.validateDeduplication(p, c, errors);
      this.validateTimestamp(p, c, errors);
      this.validateFbp(p, c, errors);
      this.validateCookies(p, c, errors);
      this.validateDataMatch(p, c, eventName, 'custom_data', errors);
      this.validateDataMatch(p, c, eventName, 'user_data', errors);
      this.validateUserData(p, c, eventName, errors);
    }

    await this.validatePhpErrors(page, errors);

    if (hasPixel && p) this.validatePixelResponse(p, errors);

    return {
      passed: errors.length === 0,
      errors,
      pixel: p,
      capi: c
    };
  }

  validateEventCounts(pixel, capi, eventName, errors) {
    const fieldContract = EVENT_FIELD_CONTRACTS[eventName];

    if (this.expectZeroEvents) {
      if (pixel.length > 0 || capi.length > 0) {
        errors.push(`Expected 0 events, found ${pixel.length} Pixel and ${capi.length} CAPI`);
      } else {
        console.log(`  ✓ No events fired (as expected for negative test)`);
      }

      return {
        passed: errors.length === 0,
        errors,
        pixel,
        capi
      };
    }

    if (fieldContract.channels.includes('pixel') && pixel.length !== 1) {
      errors.push(`Expected 1 Pixel event, found ${pixel.length}`);
    }
    if (fieldContract.channels.includes('capi') && capi.length !== 1) {
      errors.push(`Expected 1 CAPI event, found ${capi.length}`);
    }

    if (errors.length === 0) {
      console.log(`  ✓ Event counts match`);
    }

    return {
      passed: errors.length === 0,
      errors,
      pixel,
      capi
    };
  }

  validateFieldsExistence(eventName, dataSource, dataType, eventData, errors) {
    const eventFieldContract = EVENT_FIELD_CONTRACTS[eventName];
    if (!eventFieldContract || !eventFieldContract[dataSource] || !eventFieldContract[dataSource][dataType]) {
      return;
    }

    const expectedFields = eventFieldContract[dataSource][dataType];
    if (expectedFields.length === 0) {
      return;
    }

    const actualData = eventData[dataType];
    if (!actualData) {
      errors.push(`${dataSource} ${dataType} missing`);
      return;
    }

    let missing = 0;
    expectedFields.forEach(field => {
      if (!(field in actualData) || actualData[field] == null) {
        errors.push(`${dataSource} ${dataType}.${field} missing`);
        missing++;
      }
    });

    if (missing === 0) {
      console.log(`  ✓ ${dataSource} ${dataType}: All ${expectedFields.length} fields present`);
    }
  }

  validateDeduplication(p, c, errors) {
    console.log(`  ✓ Checking event deduplication...`);
    if (!p.event_id) errors.push('Pixel missing event_id');
    if (!c.event_id) errors.push('CAPI missing event_id');

    if (p.event_id && c.event_id) {
      if (p.event_id === c.event_id) {
        console.log(`    ✓ Event IDs match: ${p.event_id}`);
      } else {
        errors.push(`Event IDs mismatch: ${p.event_id} vs ${c.event_id}`);
      }
    }
  }

  async validatePhpErrors(page, errors) {
    if (page) {
      console.log(`  ✓ Checking for PHP errors...`);
      const pageContent = await page.content();
      const phpErrors = [];

      if (pageContent.includes('Fatal error')) {
        phpErrors.push('PHP Fatal error detected on page');
      }
      if (pageContent.includes('Parse error')) {
        phpErrors.push('PHP Parse error detected on page');
      }

      if (phpErrors.length > 0) {
        console.log(`    ✗ PHP errors found: ${phpErrors.length}`);
        phpErrors.forEach(err => errors.push(err));
      } else {
        console.log(`    ✓ No PHP errors`);
      }
    }
  }

  validatePixelResponse(p, errors) {
    console.log(`  ✓ Checking Pixel response...`);
    if (p.api_status) {
      if (p.api_status === 'N/A') {
        console.log(`    ✓ Pixel API: N/A (FB Pixel uses sendBeacon for large payloads - no response expected)`);
        return;
      }

      const status = Number(p.api_status);
      if (!Number.isNaN(status) && status >= 200 && status < 400) {
        if (status === 200) {
          console.log(`    ✓ Pixel API: 200 OK`);
        } else {
          console.log(`    ✓ Pixel API: ${status} redirect/success (accepted)`);
        }
        return;
      }

      errors.push(`Pixel API failed: HTTP ${p.api_status}`);
      console.log(`    ✗ Pixel API: ${p.api_status}`);
    }
  }

  validateTimestamp(pixel, capi, errors) {
    const pixelTime = pixel.timestamp != null ? pixel.timestamp : null;
    if (pixelTime == null) {
      errors.push('Pixel event missing timestamp');
      return;
    }
    const capiTime = (capi.event_time || 0) * 1000;
    const diff = Math.abs(pixelTime - capiTime);

    if (diff >= 30000) {
      errors.push(`Timestamp mismatch: ${diff}ms (max 30s)`);
    } else {
      console.log(`  ✓ Timestamp match (${diff}ms)`);
    }
  }

  validateFbp(pixel, capi, errors) {
    const pixelFbp = pixel.user_data?.fbp;
    const capiFbp = capi.user_data?.fbp;

    if (!pixelFbp) {
      errors.push(`Pixel missing fbp`);
    }
    if (!capiFbp) {
      errors.push(`CAPI missing browser_id (fbp)`);
    }

    if (pixelFbp && capiFbp && pixelFbp !== capiFbp) {
      errors.push(`FBP mismatch: ${pixelFbp} vs ${capiFbp}`);
    } else if (pixelFbp && capiFbp) {
      console.log(`  ✓ FBP match: ${pixelFbp}`);
    }
  }

  validateCookies(pixel, capi, errors) {
    if (!pixel.cookies) {
      errors.push('Pixel event missing cookies field');
      return;
    }

    if (!pixel.cookies._fbp) {
      errors.push('Cookie _fbp not present');
    }

    if (!this.fbc) return;

    if (!pixel.cookies._fbc) {
      errors.push('Cookie _fbc not present in Pixel event');
    }
    if (!capi.user_data?.fbc) {
      errors.push('fbc not present in CAPI event user data');
    }

    const normalizeFbc = (value) => {
      if (!value || typeof value !== 'string') return value;
      const parts = value.split('.');
      // Canonical format: fb.1.<timestamp>.<fbclid>[.<optional browser-specific suffix>]
      return parts.length >= 4 ? parts.slice(0, 4).join('.') : value;
    };

    const isBraveUserAgent = () => {
      const ua = String(capi.user_data?.client_user_agent || '');
      return ua.includes('Brave') || ua.includes('brave');
    };

    if (pixel.cookies._fbc && capi.user_data?.fbc) {
      const pixelFbc = pixel.cookies._fbc;
      const capiFbc = capi.user_data.fbc;

      if (pixelFbc === capiFbc) {
        console.log(`  ✓ Cookie _fbc present and matches: ${pixelFbc}`);
        return;
      }

      // Brave can append a suffix in cookie value; compare normalized core format there only.
      if (this.allowBraveFbcNormalization || isBraveUserAgent()) {
        const normalizedPixelFbc = normalizeFbc(pixelFbc);
        const normalizedCapiFbc = normalizeFbc(capiFbc);

        if (normalizedPixelFbc === normalizedCapiFbc) {
          console.log(`  ✓ Cookie _fbc matches after Brave normalization: ${normalizedPixelFbc}`);
          return;
        }
      }

      errors.push(`Cookie _fbc mismatch: ${pixelFbc} vs ${capiFbc}`);
    }
  }

  validateDataMatch(pixel, capi, eventName, dataType, errors) {
    const eventFieldContract = EVENT_FIELD_CONTRACTS[eventName];
    if (!eventFieldContract || !eventFieldContract.channels.includes('pixel') || !eventFieldContract.channels.includes('capi')) {
      return;
    }

    const pixelData = pixel[dataType];
    const capiData = capi[dataType];

    if (!pixelData || !capiData) {
      return;
    }

    const commonFields = eventFieldContract.pixel[dataType].filter(f => eventFieldContract.capi[dataType].includes(f));
    if (commonFields.length === 0) {
      return;
    }

    let mismatches = 0;
    commonFields.forEach(field => {
      const pVal = pixelData[field];
      const cVal = capiData[field];

      if (pVal === undefined || cVal === undefined) return;

      const normalize = (val) => {
        let v = typeof val === 'string' ? (() => { try { return JSON.parse(val); } catch { return val; } })() : val;

        if (v && typeof v === 'object' && !Array.isArray(v)) {
          const keys = Object.keys(v);
          if (keys.every((k, i) => k === String(i))) v = keys.map(k => v[k]);
        }

        return Array.isArray(v) ? [...v].sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b))) : v;
      };

      const pStr = JSON.stringify(normalize(pVal));
      const cStr = JSON.stringify(normalize(cVal));

      if (pStr !== cStr) {
        errors.push(`${dataType}.${field} mismatch: Pixel=${pStr} vs CAPI=${cStr}`);
        mismatches++;
      }
    });

    if (mismatches === 0) {
      console.log(`  ✓ ${dataType}: ${commonFields.length} common fields match`);
    }
  }

  validateUserData(pixel, capi, eventName, errors) {
    const fieldContract = EVENT_FIELD_CONTRACTS[eventName];
    const pixelFields = fieldContract?.pixel?.user_data || [];
    const capiFields = fieldContract?.capi?.user_data || [];

    for (const fieldName of ['em', 'external_id']) {
      if (pixelFields.includes(fieldName) && capiFields.includes(fieldName)) {
        this.validatePII(pixel, capi, errors, fieldName);
      }
    }
  }

  validatePII(pixel, capi, errors, field_name) {
    const pixelValue = pixel.user_data?.[field_name];
    const capiValue = capi.user_data?.[field_name];

    if (pixelValue || capiValue) {
      if (!pixelValue) errors.push(`Pixel missing hashed ${field_name}`);
      if (!capiValue) errors.push(`CAPI missing hashed ${field_name}`);

      if (pixelValue && capiValue && pixelValue !== capiValue) {
        errors.push(`Hashed ${field_name} mismatch: ${pixelValue} vs ${capiValue}`);
      }

      if (pixelValue && !/^[a-f0-9]{64}$/.test(pixelValue)) {
        errors.push(`Pixel ${field_name} not properly SHA256 hashed`);
      }

      if (pixelValue && capiValue && pixelValue === capiValue && /^[a-f0-9]{64}$/.test(pixelValue)) {
        console.log(`  ✓ ${field_name} hashed correctly and matches`);
      }
    }
  }

  async checkDebugLog() {
    const debugLogPath = process.env.WP_DEBUG_LOG;
    if (!debugLogPath) return;
    try {
      const data = await fs.readFile(debugLogPath, 'utf8');
      const lines = data.split('\n');
      const criticalErrors = lines.filter(line => {
        if (!/fatal|error/i.test(line)) return false;
        if (/warning/i.test(line)) return false;
        if (/Cron reschedule event error/i.test(line)) return false;
        return true;
      });

      if (criticalErrors.length > 0) {
        console.log('❌ Critical errors in debug.log:');
        criticalErrors.forEach(err => console.log('  ', err));
        throw new Error('❌ Debug log errors detected');
      }
    } catch (err) {
      if (err.code !== 'ENOENT') throw err;
    }
  }
}

module.exports = EventValidator;
