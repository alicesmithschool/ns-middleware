'use strict';

const fs = require('fs');
const path = require('path');
const https = require('https');
const { google } = require('googleapis');

// Flexible .env loading
const argv = process.argv.slice(2);
const envFlagIdx = argv.findIndex(a => a === '--env' || a === '--env-path');
let dotenvPath = process.env.DOTENV_PATH || process.env.ENV_PATH || undefined;
if (envFlagIdx !== -1 && argv[envFlagIdx + 1]) {
  dotenvPath = argv[envFlagIdx + 1];
}
try {
  require('dotenv').config(dotenvPath ? { path: path.resolve(dotenvPath) } : undefined);
} catch (_e) {}

const ROOT_DIR = path.resolve(__dirname, '..');
const SERVICE_ACCOUNT_PATH = path.join(ROOT_DIR, 'service-account.json');
const TOKEN_STORE = path.join(ROOT_DIR, '.netsuite_token.json');
const JSRSA_FILE = path.join(ROOT_DIR, 'jsrsasign-latest-all-min.js');
const COUNTRY_CODES_LOOKUP_FILE = path.join(ROOT_DIR, 'country_codes_lookup.json');
const COUNTRY_CODES_FILE = path.join(ROOT_DIR, 'country_codes.json');
const COUNTRY_CODES_ADDRESSBOOK_FILE = path.join(ROOT_DIR, 'country_codes_addressbook.json');
const VENDOR_CATEGORIES_LOOKUP_FILE = path.join(ROOT_DIR, 'vendor_categories_lookup.json');
const CURRENCIES_LOOKUP_FILE = path.join(ROOT_DIR, 'currencies_lookup.json');
const ASSA_CATEGORIES_LOOKUP_FILE = path.join(ROOT_DIR, 'assa_categories_lookup.json');
const ASSA_NOB_LOOKUP_FILE = path.join(ROOT_DIR, 'assa_nob_lookup.json');
const MSIC_LOOKUP_FILE = path.join(ROOT_DIR, 'msic_codes_lookup.json');
const STATE_CODES_LOOKUP_FILE = path.join(ROOT_DIR, 'state_codes_lookup.json');

// Sheet ID from user
const SHEET_ID = '1oYdWKQH2Igz7huhMKKsvFFDzABafRe0W7l1vD-Py45o';
const SOURCE_SHEET_NAME = 'Sheet1'; // Change if your source sheet has a different name
const SYNCED_SHEET_NAME = 'Synced';
const ERROR_SHEET_NAME = 'Errors';

// E-Invoicing field ID configuration
// Field IDs found from vendors_dump.txt - these are the actual field IDs in your NetSuite instance
const EINV_FIELD_IDS = {
  registeredName: 'custentity_tin_registeredname',      // (EInv)Registered Name
  msicCode: 'custentity_tin_msic',                     // (EInv)MSIC Code (reference field)
  addressLine1: 'custentity_tin_addrline1',             // (EInv)Address Line1
  cityName: 'custentity_tin_cityname',                  // (EInv)City Name
  countryCode: 'custentity_tin_countrycode',            // (EInv)Country Code (reference field)
  identificationCode: 'custentity_tin_id',             // (EInv)Identification Code
  identificationType: 'custentity_tin_idtype',         // (EInv)Identification Type (reference field)
  stateCode: 'custentity_tin_statecode'                // (EInv)State Code (reference field)
};

// Check if sandbox mode is enabled
const SANDBOX_MODE = process.env.SANDBOX_MODE === 'true';

// Select environment variables based on sandbox mode
const NETSUITE_DOMAIN = SANDBOX_MODE 
  ? process.env.SANDBOX_NETSUITE_DOMAIN 
  : process.env.NETSUITE_DOMAIN;
const NETSUITE_TOKEN_PATH = process.env.NETSUITE_TOKEN_PATH || '/services/rest/auth/oauth2/v1/token';
const CONSUMER_KEY = SANDBOX_MODE 
  ? process.env.SANDBOX_CONSUMER_KEY 
  : process.env.CONSUMER_KEY;
const CERTIFICATE_PRIVATE_KEY = SANDBOX_MODE 
  ? process.env.SANDBOX_CERTIFICATE_PRIVATE_KEY 
  : process.env.CERTIFICATE_PRIVATE_KEY;
const CERTIFICATE_PRIVATE_KEY_PATH = process.env.CERTIFICATE_PRIVATE_KEY_PATH;
const CERTIFICATE_KID = SANDBOX_MODE 
  ? process.env.SANDBOX_CERTIFICATE_KID 
  : process.env.CERTIFICATE_KID;
const SCOPES = process.env.SCOPES || 'restlets,rest_webservices';

const DEBUG = argv.includes('--debug');
const DRY_RUN = argv.includes('--dry-run');

function assertEnv(name, val) {
  if (!val || String(val).trim() === '') {
    throw new Error(`Missing required env var: ${name}`);
  }
}

function httpsRequest(method, urlString, headers, bodyBuffer) {
  const url = new URL(urlString);
  const options = {
    method,
    hostname: url.hostname,
    path: url.pathname + (url.search || ''),
    headers: headers || {}
  };
  return new Promise((resolve, reject) => {
    const req = https.request(options, (res) => {
      const chunks = [];
      res.on('data', (d) => chunks.push(d));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString('utf8');
        const contentType = res.headers['content-type'] || '';
        let payload = text;
        if (contentType.toLowerCase().includes('json')) {
          try { payload = JSON.parse(text); } catch (_e) {}
        } else if (text && (text.startsWith('{') || text.startsWith('['))) {
          try { payload = JSON.parse(text); } catch (_e) {}
        }
        resolve({ status: res.statusCode || 0, headers: res.headers, data: payload });
      });
    });
    req.on('error', reject);
    if (bodyBuffer && bodyBuffer.length) req.write(bodyBuffer);
    req.end();
  });
}

function httpsPostJson(urlString, obj, token) {
  const body = Buffer.from(JSON.stringify(obj));
  const headers = {
    'Content-Type': 'application/json',
    'Content-Length': body.length,
    'Accept': 'application/json'
  };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (DEBUG && obj.addressBook) {
    console.error(`[DEBUG] POST payload addressBook: ${JSON.stringify(obj.addressBook, null, 2)}`);
  }
  return httpsRequest('POST', urlString, headers, body);
}

function httpsPatchJson(urlString, obj, token) {
  const body = Buffer.from(JSON.stringify(obj));
  const headers = {
    'Content-Type': 'application/json',
    'Content-Length': body.length,
    'Accept': 'application/json'
  };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (DEBUG && obj.addressBook) {
    console.error(`[DEBUG] PATCH payload addressBook: ${JSON.stringify(obj.addressBook, null, 2)}`);
  }
  return httpsRequest('PATCH', urlString, headers, body);
}

function loadJsrsasignSafely() {
  const code = fs.readFileSync(JSRSA_FILE, 'utf8');
  const factory = new Function('require', 'module', 'exports', 'global', 'navigator', 'window', `${code}; return { KJUR: typeof KJUR!=='undefined'?KJUR:global.KJUR };`);
  const ctx = factory(require, module, exports, global, {}, {});
  if (!ctx || !ctx.KJUR) throw new Error('Failed to load jsrsasign KJUR from file');
  return ctx.KJUR;
}

function tokenExpired(record) {
  if (!record) return true;
  const now = Date.now();
  if (record.expires_at) {
    const exp = Date.parse(record.expires_at);
    return !Number.isFinite(exp) || exp - now < 15000;
  }
  if (record.fetched_at && record.raw && typeof record.raw.expires_in === 'number') {
    const fetched = Date.parse(record.fetched_at);
    const exp = fetched + record.raw.expires_in * 1000;
    return exp - now < 15000;
  }
  return true;
}

async function getValidAccessToken() {
  try {
    if (fs.existsSync(TOKEN_STORE)) {
      const txt = fs.readFileSync(TOKEN_STORE, 'utf8');
      const record = JSON.parse(txt);
      if (!tokenExpired(record) && record.raw && record.raw.access_token) {
        return record.raw.access_token;
      }
    }
  } catch (_e) {}
  // Generate new
  assertEnv('NETSUITE_DOMAIN', NETSUITE_DOMAIN);
  assertEnv('CONSUMER_KEY', CONSUMER_KEY);
  if (!(CERTIFICATE_PRIVATE_KEY && CERTIFICATE_PRIVATE_KEY.trim()) && !CERTIFICATE_PRIVATE_KEY_PATH) {
    throw new Error('Missing CERTIFICATE_PRIVATE_KEY or CERTIFICATE_PRIVATE_KEY_PATH');
  }
  assertEnv('CERTIFICATE_KID', CERTIFICATE_KID);
  const tokenPath = NETSUITE_TOKEN_PATH || '/services/rest/auth/oauth2/v1/token';
  const aud = `https://${NETSUITE_DOMAIN}${tokenPath}`;
  const now = Math.floor(Date.now() / 1000);
  const exp = now + 3600;
  const scopeArray = (SCOPES || 'restlets,rest_webservices').split(',').map(s => s.trim()).filter(Boolean);
  const header = { alg: 'PS256', typ: 'JWT', kid: CERTIFICATE_KID };
  const payload = { iss: CONSUMER_KEY, scope: scopeArray, iat: now, exp, aud };
  const pem = CERTIFICATE_PRIVATE_KEY && CERTIFICATE_PRIVATE_KEY.trim().length > 0
    ? CERTIFICATE_PRIVATE_KEY
    : fs.readFileSync(path.resolve(CERTIFICATE_PRIVATE_KEY_PATH), 'utf8');
  const KJURObj = loadJsrsasignSafely();
  const assertion = KJURObj.jws.JWS.sign('PS256', JSON.stringify(header), JSON.stringify(payload), pem);
  const form = {
    grant_type: 'client_credentials',
    client_assertion_type: 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
    client_assertion: assertion
  };
  const body = new URLSearchParams(form).toString();
  const headers = {
    'Content-Type': 'application/x-www-form-urlencoded',
    'Content-Length': Buffer.byteLength(body)
  };
  const res = await httpsRequest('POST', aud, headers, Buffer.from(body));
  if (res.status < 200 || res.status >= 300) {
    const msg = typeof res.data === 'string' ? res.data : JSON.stringify(res.data);
    throw new Error(`Token request failed: HTTP ${res.status}: ${msg}`);
  }
  const nowMs = Date.now();
  const ttlMs = typeof res.data.expires_in === 'number' ? res.data.expires_in * 1000 : 0;
  const record = {
    fetched_at: new Date(nowMs).toISOString(),
    expires_at: ttlMs ? new Date(nowMs + ttlMs).toISOString() : null,
    raw: res.data
  };
  try { fs.writeFileSync(TOKEN_STORE, JSON.stringify(record, null, 2)); } catch (_e) {}
  return res.data.access_token;
}

// Load country codes lookup
function loadCountryCodesLookup() {
  try {
    if (fs.existsSync(COUNTRY_CODES_LOOKUP_FILE)) {
      return JSON.parse(fs.readFileSync(COUNTRY_CODES_LOOKUP_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load country codes lookup: ${error.message}`);
  }
  return {};
}

// Load full country codes data
function loadCountryCodes() {
  try {
    if (fs.existsSync(COUNTRY_CODES_FILE)) {
      return JSON.parse(fs.readFileSync(COUNTRY_CODES_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load country codes: ${error.message}`);
  }
  return {};
}

// Load address book country codes lookup (2-letter codes)
function loadAddressBookCountryCodes() {
  try {
    if (fs.existsSync(COUNTRY_CODES_ADDRESSBOOK_FILE)) {
      return JSON.parse(fs.readFileSync(COUNTRY_CODES_ADDRESSBOOK_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load address book country codes: ${error.message}`);
  }
  return {};
}

// Load vendor categories lookup
function loadVendorCategoriesLookup() {
  try {
    if (fs.existsSync(VENDOR_CATEGORIES_LOOKUP_FILE)) {
      return JSON.parse(fs.readFileSync(VENDOR_CATEGORIES_LOOKUP_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load vendor categories lookup: ${error.message}`);
  }
  return {};
}

// Load currencies lookup
function loadCurrenciesLookup() {
  try {
    if (fs.existsSync(CURRENCIES_LOOKUP_FILE)) {
      return JSON.parse(fs.readFileSync(CURRENCIES_LOOKUP_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load currencies lookup: ${error.message}`);
  }
  return {};
}

// Load ASSA (cseg_assa_cos) categories lookup
function loadAssaCategoriesLookup() {
  try {
    if (fs.existsSync(ASSA_CATEGORIES_LOOKUP_FILE)) {
      return JSON.parse(fs.readFileSync(ASSA_CATEGORIES_LOOKUP_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load ASSA categories lookup: ${error.message}`);
  }
  return {};
}

// Load ASSA nature of business (cseg_assa_nob) lookup
function loadAssaNatureLookup() {
  try {
    if (fs.existsSync(ASSA_NOB_LOOKUP_FILE)) {
      return JSON.parse(fs.readFileSync(ASSA_NOB_LOOKUP_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load ASSA nature of business lookup: ${error.message}`);
  }
  return {};
}

// Load MSIC codes lookup
function loadMsicLookup() {
  try {
    if (fs.existsSync(MSIC_LOOKUP_FILE)) {
      return JSON.parse(fs.readFileSync(MSIC_LOOKUP_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load MSIC codes lookup: ${error.message}`);
  }
  return {};
}

// Load state codes lookup
function loadStateCodesLookup() {
  try {
    if (fs.existsSync(STATE_CODES_LOOKUP_FILE)) {
      return JSON.parse(fs.readFileSync(STATE_CODES_LOOKUP_FILE, 'utf8'));
    }
  } catch (error) {
    console.error(`Warning: Could not load state codes lookup: ${error.message}`);
  }
  return {};
}

function getRowValue(row, ...keys) {
  for (const key of keys) {
    if (!key) continue;
    const value = row[key];
    if (value !== undefined && value !== null) {
      const str = String(value).trim();
      if (str) {
        return str;
      }
    }
  }
  return '';
}

// Capitalize vendor name (ALL WORDS UPPERCASE)
function capitalizeVendorName(name) {
  if (!name || !name.trim()) return name;
  return name.toUpperCase().trim();
}

function stringifyJsonSafe(value) {
  if (value === undefined || value === null) {
    return '';
  }
  if (typeof value === 'string') {
    return value;
  }
  try {
    return JSON.stringify(value, null, 2);
  } catch (_err) {
    return String(value);
  }
}

function extractNetSuiteErrorDetails(responseData) {
  if (responseData && typeof responseData === 'object' && responseData['o:errorDetails']) {
    try {
      return JSON.stringify(responseData['o:errorDetails'], null, 2);
    } catch (_err) {
      return String(responseData['o:errorDetails']);
    }
  }
  return '';
}

// Get address book country object with id and refName
function getAddressBookCountry(countryValue, addressBookLookup, countryCodes) {
  if (DEBUG) {
    console.error(`[DEBUG] getAddressBookCountry called with: "${countryValue}"`);
    console.error(`[DEBUG] addressBookLookup has ${Object.keys(addressBookLookup).length} entries`);
  }
  
  if (!countryValue || !countryValue.trim()) {
    if (DEBUG) console.error(`[DEBUG] Country value is empty, defaulting to Malaysia`);
    return { id: 'MY', refName: 'Malaysia' };
  }
  
  const value = String(countryValue).trim().toUpperCase();
  if (DEBUG) console.error(`[DEBUG] Normalized country value: "${value}"`);
  
  // Try direct lookup (works for both 2-letter and 3-letter codes)
  if (addressBookLookup[value]) {
    const result = addressBookLookup[value];
    if (DEBUG) console.error(`[DEBUG] Found in addressBookLookup: "${value}" -> ${JSON.stringify(result)}`);
    return result;
  } else {
    if (DEBUG) console.error(`[DEBUG] Not found in addressBookLookup for "${value}"`);
  }
  
  // Try to find by description in country codes
  for (const [id, country] of Object.entries(countryCodes)) {
    if (country.description && country.description.toUpperCase() === value) {
      const refName = formatCountryNameForRefName(country.description);
      const result = { id: country.code2 || 'MY', refName: refName || 'Malaysia' };
      if (DEBUG) console.error(`[DEBUG] Found by description: "${value}" -> ${JSON.stringify(result)} (from country ${id})`);
      return result;
    }
    // Also check if value matches 3-letter code
    if (country.code && country.code.toUpperCase() === value) {
      const refName = formatCountryNameForRefName(country.description);
      const result = { id: country.code2 || 'MY', refName: refName || 'Malaysia' };
      if (DEBUG) console.error(`[DEBUG] Found by 3-letter code: "${value}" -> ${JSON.stringify(result)} (from country ${id})`);
      return result;
    }
    // Check if value matches 2-letter code
    if (country.code2 && country.code2.toUpperCase() === value) {
      const refName = formatCountryNameForRefName(country.description);
      const result = { id: country.code2, refName: refName || 'Malaysia' };
      if (DEBUG) console.error(`[DEBUG] Found by 2-letter code: "${value}" -> ${JSON.stringify(result)} (from country ${id})`);
      return result;
    }
  }
  
  // Default to Malaysia if not found
  if (DEBUG) console.error(`[DEBUG] No match found, defaulting to Malaysia`);
  return { id: 'MY', refName: 'Malaysia' };
}

// Format country name for NetSuite refName (convert to proper case)
function formatCountryNameForRefName(description) {
  if (!description) return '';
  
  // Common mappings for NetSuite refName format
  const nameMappings = {
    'UNITED STATES OF AMERICA': 'United States',
    'UNITED STATES': 'United States',
    'MALAYSIA': 'Malaysia',
    'UNITED KINGDOM': 'United Kingdom',
    'PEOPLE\'S REPUBLIC OF CHINA': 'China',
    'RUSSIAN FEDERATION': 'Russia',
    'REPUBLIC OF KOREA': 'South Korea',
    'DEMOCRATIC PEOPLE\'S REPUBLIC OF KOREA': 'North Korea'
  };
  
  const upperDesc = description.toUpperCase();
  if (nameMappings[upperDesc]) {
    return nameMappings[upperDesc];
  }
  
  // Default: convert to title case
  return description.toLowerCase()
    .split(' ')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

// Get country code ID from country value (code or name)
function getCountryCodeId(countryValue, countryCodesLookup, countryCodes) {
  if (!countryValue || !countryValue.trim()) {
    return '158'; // Default to Malaysia (MYS)
  }
  
  const value = String(countryValue).trim().toUpperCase();
  
  // Try direct code lookup (e.g., "MYS", "USA")
  if (countryCodesLookup[value]) {
    return countryCodesLookup[value];
  }
  
  // Try to find by description (case-insensitive)
  for (const [id, country] of Object.entries(countryCodes)) {
    if (country.description && country.description.toUpperCase() === value) {
      return id;
    }
    // Also check if value matches code
    if (country.code && country.code.toUpperCase() === value) {
      return id;
    }
  }
  
  // Default to Malaysia if not found
  return '158';
}

// Map Google Sheet row to NetSuite vendor format
function mapSheetRowToNetSuiteVendor(row, countryCodesLookup, countryCodes, addressBookLookup, categoriesLookup, currenciesLookup, assaCategoriesLookup, assaNatureLookup, msicLookup, stateCodesLookup) {
  const vendor = {};

  // Basic fields
  if (row.entityId || row.Code) {
    vendor.entityId = String(row.entityId || row.Code || '').trim();
  }
  if (row.legalName || row.Supplier_Name) {
    const rawName = String(row.legalName || row.Supplier_Name || '').trim();
    vendor.legalName = capitalizeVendorName(rawName);
  }
  if (row.email || row.Email_1) {
    vendor.email = String(row.email || row.Email_1 || '').trim();
  }
  if (row.phone || row.Phone) {
    vendor.phone = String(row.phone || row.Phone || '').trim();
  }

  // Is Active
  if (row.Is_Active !== undefined) {
    const isActiveStr = String(row.Is_Active || '').toLowerCase();
    vendor.isInactive = !(isActiveStr === 'yes' || isActiveStr === 'true' || isActiveStr === '1');
  }

  // Address book - create default billing address
  // Use both id (2-letter ISO) and refName (full country name) for address book country
  if (DEBUG) {
    console.error(`[DEBUG] Address book - row.Country value: "${row.Country}"`);
    console.error(`[DEBUG] Address book - addressBookLookup sample keys: ${Object.keys(addressBookLookup).slice(0, 10).join(', ')}`);
  }
  const addressBookCountry = getAddressBookCountry(row.Country, addressBookLookup, countryCodes);
  if (DEBUG) {
    console.error(`[DEBUG] Address book - resolved country: ${JSON.stringify(addressBookCountry)}`);
  }
  
  // Check if country is Malaysia (MY)
  const isMalaysia = addressBookCountry.id === 'MY';
  
  // Custom fields - with fallback for non-Malaysia countries
  if (isMalaysia) {
    // For Malaysia, use values from sheet
    if (row.TIN_Number) {
      vendor.custentity_tin_no = String(row.TIN_Number).trim();
    }
    if (row.SST_Number) {
      vendor.custentity_tin_sstregisterno = String(row.SST_Number).trim();
    }
    if (row.Tourism_Tax) {
      vendor.custentity_tourism_tax = String(row.Tourism_Tax).trim();
    }
  } else {
    // For non-Malaysia countries, use default values
    vendor.custentity_tin_no = 'EI00000000030';
    vendor.custentity_tin_sstregisterno = 'NA';
    vendor.custentity_tourism_tax = 'NA';
    if (DEBUG) {
      console.error(`[DEBUG] Country is not Malaysia (${addressBookCountry.id}), using default values for TIN, SST, and Tourism Tax`);
    }
  }
  
  // Use both id and refName - NetSuite requires both for address book country
  const addressBookCountryField = addressBookCountry;
  
  const addressBook = {
    items: [{
      defaultBilling: true,
      defaultShipping: true,
      addressBookAddress: {
        addr1: String(row.Address_1 || row.Address || '').trim(),
        addr2: String(row.Address_2 || '').trim(),
        city: String(row.City || '').trim(),
        state: String(row.State || '').trim(),
        zip: String(row.Zip || '').trim(),
        country: addressBookCountryField
      }
    }]
  };
  if (DEBUG) {
    console.error(`[DEBUG] Address book payload: ${JSON.stringify(addressBook, null, 2)}`);
  }

  // Always add addressBook if we have a country or at least one address field
  // This ensures the country is set even if other address fields are empty
  if (addressBookCountry.id ||
      addressBook.items[0].addressBookAddress.addr1 ||
      addressBook.items[0].addressBookAddress.city ||
      addressBook.items[0].addressBookAddress.state) {
    vendor.addressBook = addressBook;
  }

  // Category sourced primarily from Vendor_Category column (fallback to Category_of_Suppliers for backwards compatibility)
  const vendorCategoryValue = getRowValue(row, 'Vendor_Category', 'Vendor Category');
  const fallbackCategoryValue = vendorCategoryValue ? '' : getRowValue(row, 'Category_of_Suppliers', 'Category of Suppliers');
  const categorySource = vendorCategoryValue ? 'Vendor_Category' : (fallbackCategoryValue ? 'Category_of_Suppliers (fallback)' : null);
  const categoryValue = vendorCategoryValue || fallbackCategoryValue;
  if (categoryValue) {
    const categoryLookup = categoriesLookup[categoryValue.toUpperCase()] || categoriesLookup[categoryValue];
    if (categoryLookup && categoryLookup.id) {
      vendor.category = { id: categoryLookup.id, refName: categoryLookup.refName || categoryValue };
      if (DEBUG) {
        console.error(`[DEBUG] Setting category by ID from ${categorySource}: ${JSON.stringify(vendor.category)} (lookup refName: ${categoryLookup.refName})`);
      }
    } else {
      vendor.category = { refName: categoryValue };
      if (DEBUG) {
        console.error(`[DEBUG] Category not found in lookup (source ${categorySource}), using refName: ${JSON.stringify(vendor.category)}`);
      }
    }
  }

  // Custom segment sourced from Category_of_Suppliers (fallback to Nature_of_Business for backwards compatibility)
  const csegValueRaw = getRowValue(row, 'Category_of_Suppliers', 'Category of Suppliers');
  const csegFallback = csegValueRaw ? '' : getRowValue(row, 'Nature_of_Business', 'Nature of Business');
  const csegSource = csegValueRaw ? 'Category_of_Suppliers' : (csegFallback ? 'Nature_of_Business (fallback)' : null);
  const csegValue = csegValueRaw || csegFallback;
  if (csegValue) {
    const csegLookup = assaCategoriesLookup[csegValue.toUpperCase()] || assaCategoriesLookup[csegValue];
    if (csegLookup && csegLookup.id) {
      vendor.cseg_assa_cos = { id: csegLookup.id, refName: csegLookup.refName || csegValue };
      if (DEBUG) {
        console.error(`[DEBUG] Setting cseg_assa_cos by ID from ${csegSource}: ${JSON.stringify(vendor.cseg_assa_cos)}`);
      }
    } else {
      vendor.cseg_assa_cos = { refName: csegValue };
      if (DEBUG) {
        console.error(`[DEBUG] ASSA category not found in lookup (source ${csegSource}), using refName: ${JSON.stringify(vendor.cseg_assa_cos)}`);
      }
    }
  }

  // ASSA Nature of Business custom segment (cseg_assa_nob) sourced from Nature_of_Business column
  const natureValue = getRowValue(row, 'Nature_of_Business', 'Nature of Business');
  if (natureValue) {
    const natureLookup = assaNatureLookup[natureValue.toUpperCase()] || assaNatureLookup[natureValue];
    if (natureLookup && natureLookup.id) {
      vendor.cseg_assa_nob = { id: natureLookup.id, refName: natureLookup.refName || natureValue };
      if (DEBUG) {
        console.error(`[DEBUG] Setting cseg_assa_nob by ID: ${JSON.stringify(vendor.cseg_assa_nob)}`);
      }
    } else {
      vendor.cseg_assa_nob = { refName: natureValue };
      if (DEBUG) {
        console.error(`[DEBUG] ASSA nature not found in lookup, using refName: ${JSON.stringify(vendor.cseg_assa_nob)}`);
      }
    }
  }

  // Currency (use ID if available, otherwise fallback to refName) - only include if not empty
  if (row.Primary_Currency && String(row.Primary_Currency).trim()) {
    const currencyValue = String(row.Primary_Currency).trim();
    // Try to find currency by symbol (e.g., "USD") or refName (e.g., "US Dollar") - case-insensitive
    const currencyLookup = currenciesLookup[currencyValue.toUpperCase()] || currenciesLookup[currencyValue];
    if (currencyLookup && currencyLookup.id) {
      // Use ID if found (and include refName to help NetSuite UI display)
      vendor.currency = { id: currencyLookup.id, refName: currencyLookup.refName || currencyValue };
      if (DEBUG) {
        console.error(`[DEBUG] Setting currency by ID: ${JSON.stringify(vendor.currency)} (lookup symbol: ${currencyLookup.symbol}, refName: ${currencyLookup.refName})`);
      }
    } else {
      // Fallback to refName if ID not found
      vendor.currency = { refName: currencyValue };
      if (DEBUG) {
        console.error(`[DEBUG] Currency not found in lookup, using refName: ${JSON.stringify(vendor.currency)}`);
      }
    }
  }

  // E-Invoicing required fields (Malaysia E-Invoicing)
  // Company Name - use legalName/Supplier_Name
  if (vendor.legalName) {
    vendor.companyName = vendor.legalName;
  }

  // (EInv)Registered Name - use Supplier_Name if available, otherwise legalName
  if (row.Supplier_Name) {
    const rawName = String(row.Supplier_Name).trim();
    vendor[EINV_FIELD_IDS.registeredName] = capitalizeVendorName(rawName);
  } else if (vendor.legalName) {
    vendor[EINV_FIELD_IDS.registeredName] = vendor.legalName; // Already capitalized above
  }

  // (EInv)MSIC Code - use ID if available, otherwise fallback to refName
  // For non-Malaysia countries, always use default
  if (!isMalaysia) {
    vendor[EINV_FIELD_IDS.msicCode] = { refName: '00000 : NOT APPLICABLE' };
    if (DEBUG) {
      console.error(`[DEBUG] Country is not Malaysia, using default MSIC code: ${JSON.stringify(vendor[EINV_FIELD_IDS.msicCode])}`);
    }
  } else {
    const msicValue = getRowValue(row, 'MSIC_Code', 'MSIC Code');
    if (msicValue) {
      const msicLookupResult = msicLookup[msicValue.toUpperCase()] || msicLookup[msicValue];
      if (msicLookupResult && msicLookupResult.id) {
        // Use ID if found (and include refName so NetSuite UI keeps the label)
        vendor[EINV_FIELD_IDS.msicCode] = { id: msicLookupResult.id, refName: msicLookupResult.refName || msicValue };
        if (DEBUG) {
          console.error(`[DEBUG] Setting MSIC code by ID: ${JSON.stringify(vendor[EINV_FIELD_IDS.msicCode])} (lookup refName: ${msicLookupResult.refName})`);
        }
      } else {
        // Fallback to refName if ID not found
        vendor[EINV_FIELD_IDS.msicCode] = { refName: msicValue };
        if (DEBUG) {
          console.error(`[DEBUG] MSIC code not found in lookup, using refName: ${JSON.stringify(vendor[EINV_FIELD_IDS.msicCode])}`);
        }
      }
    } else {
      // Always set MSIC Code (required field) - use default if not provided
      vendor[EINV_FIELD_IDS.msicCode] = { refName: '00000 : NOT APPLICABLE' };
      if (DEBUG) {
        console.error(`[DEBUG] No MSIC code provided, using default: ${JSON.stringify(vendor[EINV_FIELD_IDS.msicCode])}`);
      }
    }
  }

  // (EInv)Address Line1 - use Address_1 or Address
  if (row.Address_1 || row.Address) {
    vendor[EINV_FIELD_IDS.addressLine1] = String(row.Address_1 || row.Address || '').trim();
  }

  // (EInv)City Name - use City
  if (row.City) {
    vendor[EINV_FIELD_IDS.cityName] = String(row.City).trim();
  }

  // (EInv)Country Code - use ID instead of refName
  const countryId = getCountryCodeId(row.Country, countryCodesLookup, countryCodes);
  vendor[EINV_FIELD_IDS.countryCode] = { id: countryId };

  // (EInv)Identification Code - this is separate from TIN_Number
  // Use Identification_Code from sheet, or TIN_Number, or SST_Number as fallback
  // For non-Malaysia countries, use the default TIN_Number value
  if (!isMalaysia) {
    vendor[EINV_FIELD_IDS.identificationCode] = 'EI00000000030';
    if (DEBUG) {
      console.error(`[DEBUG] Country is not Malaysia, using default Identification Code: ${vendor[EINV_FIELD_IDS.identificationCode]}`);
    }
  } else if (row.Identification_Code || row['Identification Code']) {
    vendor[EINV_FIELD_IDS.identificationCode] = String(row.Identification_Code || row['Identification Code'] || '').trim();
  } else if (row.TIN_Number) {
    vendor[EINV_FIELD_IDS.identificationCode] = String(row.TIN_Number).trim();
  } else if (row.SST_Number) {
    vendor[EINV_FIELD_IDS.identificationCode] = String(row.SST_Number).trim();
  }

  // (EInv)Identification Type - reference field, use refName
  // Format should be like "BRN : Business Registration No."
  if (row.Identification_Type || row['Identification Type']) {
    const idTypeValue = String(row.Identification_Type || row['Identification Type'] || '').trim();
    if (idTypeValue) {
      // Check if it's already in the correct format
      if (idTypeValue.includes(' : ')) {
        vendor[EINV_FIELD_IDS.identificationType] = { refName: idTypeValue };
      } else {
        // Try common mappings
        const idTypeUpper = idTypeValue.toUpperCase();
        if (idTypeUpper === 'BRN') {
          vendor[EINV_FIELD_IDS.identificationType] = { refName: 'BRN : Business Registration No.' };
        } else if (idTypeUpper === 'NRIC') {
          vendor[EINV_FIELD_IDS.identificationType] = { refName: 'NRIC' };
        } else {
          vendor[EINV_FIELD_IDS.identificationType] = { refName: idTypeValue };
        }
      }
    }
  } else if (row.SST_Number) {
    // If SST Number exists, likely BRN (Business Registration Number)
    vendor[EINV_FIELD_IDS.identificationType] = { refName: 'BRN : Business Registration No.' };
  } else if (row.TIN_Number) {
    // If TIN exists, might be NRIC or other - default to BRN
    vendor[EINV_FIELD_IDS.identificationType] = { refName: 'BRN : Business Registration No.' };
  }
  // Always set Identification Type (required field)
  if (!vendor[EINV_FIELD_IDS.identificationType]) {
    vendor[EINV_FIELD_IDS.identificationType] = { refName: 'BRN : Business Registration No.' };
  }

  // (EInv)State Code - reference field, use lookup to match state name
  if (row.State) {
    const stateValue = String(row.State).trim();
    
    // Check if it's already in the correct format (e.g., "17 : Not Applicable")
    if (stateValue.includes(' : ')) {
      // Try to find by full refName
      const stateLookup = stateCodesLookup[stateValue.toUpperCase()] || stateCodesLookup[stateValue];
      if (stateLookup && stateLookup.id) {
        vendor[EINV_FIELD_IDS.stateCode] = { id: stateLookup.id, refName: stateLookup.refName };
        if (DEBUG) {
          console.error(`[DEBUG] State code found by refName: ${JSON.stringify(vendor[EINV_FIELD_IDS.stateCode])}`);
        }
      } else {
        // Use as-is if not found in lookup
        vendor[EINV_FIELD_IDS.stateCode] = { refName: stateValue };
        if (DEBUG) {
          console.error(`[DEBUG] State code not found in lookup, using refName as-is: ${JSON.stringify(vendor[EINV_FIELD_IDS.stateCode])}`);
        }
      }
    } else {
      // Try to find by state name (e.g., "Selangor")
      const stateLookup = stateCodesLookup[stateValue.toUpperCase()] || stateCodesLookup[stateValue];
      if (stateLookup && stateLookup.id) {
        vendor[EINV_FIELD_IDS.stateCode] = { id: stateLookup.id, refName: stateLookup.refName };
        if (DEBUG) {
          console.error(`[DEBUG] State code found by name "${stateValue}": ${JSON.stringify(vendor[EINV_FIELD_IDS.stateCode])}`);
        }
      } else {
        // Default to "Not Applicable" if state not found
        vendor[EINV_FIELD_IDS.stateCode] = { refName: '17 : Not Applicable' };
        if (DEBUG) {
          console.error(`[DEBUG] State "${stateValue}" not found in lookup, using default: ${JSON.stringify(vendor[EINV_FIELD_IDS.stateCode])}`);
        }
      }
    }
  } else {
    // Always set State Code (required field) - use default if not provided
    vendor[EINV_FIELD_IDS.stateCode] = { refName: '17 : Not Applicable' };
    if (DEBUG) {
      console.error(`[DEBUG] No state provided, using default: ${JSON.stringify(vendor[EINV_FIELD_IDS.stateCode])}`);
    }
  }

  return vendor;
}

// Check if vendor exists by entityId
async function findVendorByEntityId(entityId, token) {
  if (!entityId || !entityId.trim()) return null;
  try {
    const url = `https://${NETSUITE_DOMAIN}/services/rest/record/v1/vendor?q=entityId IS "${encodeURIComponent(entityId.trim())}"`;
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const res = await httpsRequest('GET', url, headers);
    if (res.status >= 200 && res.status < 300 && res.data && res.data.items && res.data.items.length > 0) {
      return res.data.items[0];
    }
  } catch (err) {
    if (DEBUG) console.error(`[DEBUG] Error finding vendor: ${err.message}`);
  }
  return null;
}

// Create or update vendor in NetSuite
async function upsertVendor(vendorPayload, token, retryWithoutRefs = false, fieldsToRemove = []) {
  assertEnv('NETSUITE_DOMAIN', NETSUITE_DOMAIN);
  
  // If retrying after reference error, remove only the specific problematic fields
  if (retryWithoutRefs) {
    // Only remove fields that were specified as problematic
    if (fieldsToRemove.includes('category')) {
      delete vendorPayload.category;
    }
    if (fieldsToRemove.includes('cseg_assa_cos')) {
      delete vendorPayload.cseg_assa_cos;
    }
    if (fieldsToRemove.includes('cseg_assa_nob')) {
      delete vendorPayload.cseg_assa_nob;
    }
    if (fieldsToRemove.includes('currency')) {
      delete vendorPayload.currency;
    }
    // DO NOT delete address book country - it uses 2-letter ISO codes with refName which should be valid
    // Keep E-Invoicing fields - they're required, not reference fields
    if (DEBUG) console.error(`[DEBUG] Retrying without problematic fields: ${fieldsToRemove.join(', ') || 'none'}`);
  }
  
  // Check if vendor exists
  const existing = vendorPayload.entityId ? await findVendorByEntityId(vendorPayload.entityId, token) : null;
  
  if (existing && existing.id) {
    // Update existing vendor
    const url = `https://${NETSUITE_DOMAIN}/services/rest/record/v1/vendor/${encodeURIComponent(existing.id)}`;
    if (DEBUG) console.error(`[DEBUG] Updating vendor ${existing.id} (entityId: ${vendorPayload.entityId})`);
    if (DRY_RUN) {
      console.error(`[DRY-RUN] Would update vendor: ${JSON.stringify(vendorPayload, null, 2)}`);
      return { status: 200, data: { id: existing.id, action: 'update' } };
    }
    const res = await httpsPatchJson(url, vendorPayload, token);
    return res;
  } else {
    // Create new vendor
    const url = `https://${NETSUITE_DOMAIN}/services/rest/record/v1/vendor`;
    if (DEBUG) console.error(`[DEBUG] Creating new vendor (entityId: ${vendorPayload.entityId})`);
    if (DRY_RUN) {
      console.error(`[DRY-RUN] Would create vendor: ${JSON.stringify(vendorPayload, null, 2)}`);
      return { status: 201, data: { action: 'create' } };
    }
    const res = await httpsPostJson(url, vendorPayload, token);
    return res;
  }
}

// Ensure 'Synced' sheet exists
async function ensureSyncedSheet(sheets, spreadsheetId) {
  try {
    // Get all sheets
    const metadata = await sheets.spreadsheets.get({ spreadsheetId });
    const syncedSheet = metadata.data.sheets.find(s => s.properties.title === SYNCED_SHEET_NAME);
    
    if (!syncedSheet) {
      // Create the 'Synced' sheet
      await sheets.spreadsheets.batchUpdate({
        spreadsheetId,
        requestBody: {
          requests: [{
            addSheet: {
              properties: {
                title: SYNCED_SHEET_NAME
              }
            }
          }]
        }
      });
      console.error(`Created "${SYNCED_SHEET_NAME}" sheet`);
    }
  } catch (error) {
    console.error(`Warning: Could not ensure Synced sheet exists: ${error.message}`);
  }
}

// Ensure 'Errors' sheet exists
async function ensureErrorSheet(sheets, spreadsheetId) {
  try {
    // Get all sheets
    const metadata = await sheets.spreadsheets.get({ spreadsheetId });
    const errorSheet = metadata.data.sheets.find(s => s.properties.title === ERROR_SHEET_NAME);
    
    if (!errorSheet) {
      // Create the 'Errors' sheet
      await sheets.spreadsheets.batchUpdate({
        spreadsheetId,
        requestBody: {
          requests: [{
            addSheet: {
              properties: {
                title: ERROR_SHEET_NAME
              }
            }
          }]
        }
      });
      console.error(`Created "${ERROR_SHEET_NAME}" sheet`);
    }
  } catch (error) {
    console.error(`Warning: Could not ensure Errors sheet exists: ${error.message}`);
  }
}

// Write errors to Errors sheet
async function writeErrorsToSheet(sheets, spreadsheetId, headers, errors) {
  if (!errors || errors.length === 0) {
    return;
  }

  try {
    // Ensure Errors sheet exists
    await ensureErrorSheet(sheets, spreadsheetId);
    
    // Get existing headers from Errors sheet
    const errorData = await sheets.spreadsheets.values.get({
      spreadsheetId,
      range: `${ERROR_SHEET_NAME}!A1:ZZ1`
    });
    
    const hasHeaders = errorData.data.values && errorData.data.values.length > 0;
    
    // Error sheet headers: Timestamp, Row_Key, Error_Message, and all original headers
    const errorHeaders = ['Timestamp', 'Row_Key', 'Error_Message', 'NetSuite_Response', ...headers];
    
    if (!hasHeaders) {
      // Add headers to Errors sheet
      await sheets.spreadsheets.values.update({
        spreadsheetId,
        range: `${ERROR_SHEET_NAME}!A1`,
        valueInputOption: 'RAW',
        requestBody: { values: [errorHeaders] }
      });
    }
    
    // Prepare error rows
    const errorRows = errors.map(error => {
      const timestamp = new Date().toISOString();
      const rowKey = error.row || 'Unknown';
      const errorMessage = error.error || 'Unknown error';
      const rowData = error.data || {};
      const responseStr = error.response || '';
      
      // Create row with timestamp, row key, error message, and all original column values
      return [
        timestamp,
        rowKey,
        errorMessage,
        responseStr,
        ...headers.map(h => rowData[h] || '')
      ];
    });
    
    // Append error rows to Errors sheet
    await sheets.spreadsheets.values.append({
      spreadsheetId,
      range: `${ERROR_SHEET_NAME}!A:ZZ`,
      valueInputOption: 'RAW',
      insertDataOption: 'INSERT_ROWS',
      requestBody: { values: errorRows }
    });
    
    console.error(`  ✓ Wrote ${errors.length} error(s) to "${ERROR_SHEET_NAME}" sheet`);
  } catch (error) {
    console.error(`  ✗ Error writing to Errors sheet: ${error.message}`);
    if (DEBUG) {
      console.error(`  [DEBUG] Error details: ${error.stack}`);
    }
  }
}

// Move row to Synced sheet (appends to Synced, returns true if successful)
async function moveRowToSyncedSheet(sheets, spreadsheetId, headers, rowData) {
  try {
    // Ensure Synced sheet exists
    await ensureSyncedSheet(sheets, spreadsheetId);
    
    // Get headers from source sheet if Synced sheet is empty
    const syncedData = await sheets.spreadsheets.values.get({
      spreadsheetId,
      range: `${SYNCED_SHEET_NAME}!A1:ZZ1`
    });
    
    const hasHeaders = syncedData.data.values && syncedData.data.values.length > 0;
    
    if (!hasHeaders) {
      // Add headers to Synced sheet
      await sheets.spreadsheets.values.update({
        spreadsheetId,
        range: `${SYNCED_SHEET_NAME}!A1`,
        valueInputOption: 'RAW',
        requestBody: { values: [headers] }
      });
    }
    
    // Append row to Synced sheet
    const rowValues = headers.map(h => rowData[h] || '');
    await sheets.spreadsheets.values.append({
      spreadsheetId,
      range: `${SYNCED_SHEET_NAME}!A:ZZ`,
      valueInputOption: 'RAW',
      insertDataOption: 'INSERT_ROWS',
      requestBody: { values: [rowValues] }
    });
    
    return true;
  } catch (error) {
    console.error(`Error moving row to Synced sheet: ${error.message}`);
    return false;
  }
}

// Get sheet ID by name
async function getSheetId(sheets, spreadsheetId, sheetName) {
  try {
    const metadata = await sheets.spreadsheets.get({ spreadsheetId });
    if (!metadata || !metadata.data || !metadata.data.sheets) {
      console.error(`[DEBUG] Invalid metadata structure`);
      return null;
    }
    
    // Try exact match first
    let sheet = metadata.data.sheets.find(s => {
      if (!s || !s.properties) return false;
      return s.properties.title === sheetName;
    });
    
    // If not found, try case-insensitive match
    if (!sheet) {
      sheet = metadata.data.sheets.find(s => {
        if (!s || !s.properties || !s.properties.title) return false;
        return s.properties.title.toLowerCase() === sheetName.toLowerCase();
      });
    }
    
    if (sheet && sheet.properties) {
      const sheetId = sheet.properties.sheetId;
      // sheetId can be 0 (first sheet), so check for undefined/null specifically
      if (sheetId !== undefined && sheetId !== null) {
        if (DEBUG) {
          console.error(`[DEBUG] Found sheet "${sheet.properties.title}" with ID ${sheetId}`);
        }
        return sheetId;
      } else {
        console.error(`[DEBUG] Sheet "${sheet.properties.title}" found but sheetId is ${sheetId}`);
      }
    }
    
    console.error(`[DEBUG] Sheet "${sheetName}" not found. Available sheets: ${metadata.data.sheets.map(s => s.properties?.title || 'N/A').join(', ')}`);
    if (DEBUG) {
      console.error(`[DEBUG] Sheet objects: ${JSON.stringify(metadata.data.sheets.map(s => ({ title: s.properties?.title, sheetId: s.properties?.sheetId })), null, 2)}`);
    }
    
    return null;
  } catch (error) {
    console.error(`Error in getSheetId: ${error.message}`);
    if (DEBUG) {
      console.error(`[DEBUG] Error stack: ${error.stack}`);
    }
    return null;
  }
}

async function main() {
  try {
    // Load service account credentials
    if (!fs.existsSync(SERVICE_ACCOUNT_PATH)) {
      throw new Error(`Service account file not found at: ${SERVICE_ACCOUNT_PATH}`);
    }

    const serviceAccount = JSON.parse(fs.readFileSync(SERVICE_ACCOUNT_PATH, 'utf8'));

    // Authenticate with Google Sheets API (read and write)
    const auth = new google.auth.GoogleAuth({
      credentials: serviceAccount,
      scopes: [
        'https://www.googleapis.com/auth/spreadsheets' // Full access including delete
      ]
    });

    const authClient = await auth.getClient();
    const sheets = google.sheets({ version: 'v4', auth: authClient });

    // Get NetSuite token
    const token = await getValidAccessToken();

    // Load country codes lookup
    const countryCodesLookup = loadCountryCodesLookup();
    const countryCodes = loadCountryCodes();
    const addressBookLookup = loadAddressBookCountryCodes();
    const categoriesLookup = loadVendorCategoriesLookup();
    const currenciesLookup = loadCurrenciesLookup();
    const assaCategoriesLookup = loadAssaCategoriesLookup();
    const assaNatureLookup = loadAssaNatureLookup();
    const msicLookup = loadMsicLookup();
    const stateCodesLookup = loadStateCodesLookup();
    
    if (Object.keys(countryCodesLookup).length === 0) {
      console.error('Warning: Country codes lookup is empty. Run "npm run fetch-country-codes" first.');
    } else {
      console.error(`Loaded ${Object.keys(countryCodesLookup).length} country codes`);
    }
    if (Object.keys(addressBookLookup).length === 0) {
      console.error('Warning: Address book country codes lookup is empty. Run "npm run fetch-country-codes" first.');
    } else {
      console.error(`Loaded ${Object.keys(addressBookLookup).length} address book country codes`);
      if (DEBUG) {
        console.error(`[DEBUG] Sample addressBookLookup entries:`);
        console.error(`[DEBUG]   USA=${JSON.stringify(addressBookLookup['USA'])}`);
        console.error(`[DEBUG]   US=${JSON.stringify(addressBookLookup['US'])}`);
        console.error(`[DEBUG]   MYS=${JSON.stringify(addressBookLookup['MYS'])}`);
        console.error(`[DEBUG]   MY=${JSON.stringify(addressBookLookup['MY'])}`);
      }
    }
    if (Object.keys(categoriesLookup).length === 0) {
      console.error('Warning: Vendor categories lookup is empty. Run "npm run fetch-vendor-categories" first.');
    } else {
      console.error(`Loaded ${Object.keys(categoriesLookup).length} vendor category lookup entries`);
      if (DEBUG) {
        const sampleCategory = categoriesLookup['TRADE CREDITORS'] || categoriesLookup['Trade Creditors'];
        console.error(`[DEBUG] Sample category lookup for "Trade Creditors": ${JSON.stringify(sampleCategory)}`);
      }
    }
    if (Object.keys(currenciesLookup).length === 0) {
      console.error('Warning: Currencies lookup is empty. Run "npm run fetch-currencies" first.');
    } else {
      console.error(`Loaded ${Object.keys(currenciesLookup).length} currency lookup entries`);
      if (DEBUG) {
        console.error(`[DEBUG] Sample currency lookup for "USD": ${JSON.stringify(currenciesLookup['USD'])}`);
      }
    }
    if (Object.keys(assaCategoriesLookup).length === 0) {
      console.error('Warning: ASSA categories lookup is empty. Run "npm run fetch-assa-categories" first.');
    } else {
      console.error(`Loaded ${Object.keys(assaCategoriesLookup).length} ASSA category lookup entries`);
      if (DEBUG) {
        const sampleAssa = assaCategoriesLookup['TRADE CREDITORS'] || assaCategoriesLookup['Trade Creditors'];
        console.error(`[DEBUG] Sample ASSA lookup for "Trade Creditors": ${JSON.stringify(sampleAssa)}`);
      }
    }
    if (Object.keys(assaNatureLookup).length === 0) {
      console.error('Warning: ASSA nature of business lookup is empty. Run "npm run fetch-assa-nature" first.');
    } else {
      console.error(`Loaded ${Object.keys(assaNatureLookup).length} ASSA nature of business lookup entries`);
      if (DEBUG) {
        const sampleAssaNobKey = Object.keys(assaNatureLookup)[0];
        if (sampleAssaNobKey) {
          console.error(`[DEBUG] Sample ASSA NOB lookup for "${sampleAssaNobKey}": ${JSON.stringify(assaNatureLookup[sampleAssaNobKey])}`);
        }
      }
    }
    if (Object.keys(msicLookup).length === 0) {
      console.error('Warning: MSIC codes lookup is empty. Run "npm run fetch-msic-codes" first.');
    } else {
      console.error(`Loaded ${Object.keys(msicLookup).length} MSIC code lookup entries`);
      if (DEBUG) {
        const sampleMsicKey = Object.keys(msicLookup)[0];
        if (sampleMsicKey) {
          console.error(`[DEBUG] Sample MSIC lookup for "${sampleMsicKey}": ${JSON.stringify(msicLookup[sampleMsicKey])}`);
        }
      }
    }
    if (Object.keys(stateCodesLookup).length === 0) {
      console.error('Warning: State codes lookup is empty. Run "npm run fetch-state-codes" first.');
    } else {
      console.error(`Loaded ${Object.keys(stateCodesLookup).length} state code lookup entries`);
      if (DEBUG) {
        const sampleStateKey = Object.keys(stateCodesLookup)[0];
        if (sampleStateKey) {
          console.error(`[DEBUG] Sample state lookup for "${sampleStateKey}": ${JSON.stringify(stateCodesLookup[sampleStateKey])}`);
        }
      }
    }

    // Read data from source sheet
    const response = await sheets.spreadsheets.values.get({
      spreadsheetId: SHEET_ID,
      range: `${SOURCE_SHEET_NAME}!A:ZZ`,
    });

    const rows = response.data.values || [];

    if (rows.length === 0) {
      console.error('No data found in the sheet.');
      return;
    }

    // First row contains column headers
    const headers = rows[0].map((h, idx) => {
      return h && String(h).trim() ? String(h).trim() : `Column_${String.fromCharCode(65 + (idx % 26))}`;
    });

    console.error(`Found ${headers.length} columns: ${headers.join(', ')}`);
    console.error(`Found ${rows.length - 1} data rows to process`);

    // Process each row
    let successCount = 0;
    let errorCount = 0;
    const errors = [];
    const rowsToDelete = []; // Collect successful rows to delete (in reverse order)

    for (let i = 1; i < rows.length; i++) {
      const row = rows[i];
      const rowObj = {};
      
      headers.forEach((header, colIndex) => {
        const value = row[colIndex] !== undefined ? row[colIndex] : '';
        rowObj[header] = value;
      });

      // Skip empty rows
      if (!Object.values(rowObj).some(val => val !== '')) {
        continue;
      }

      const rowKey = rowObj.Key || rowObj.Code || `Row_${i}`;
      console.error(`\nProcessing ${rowKey} (row ${i})...`);

      try {
        // Map to NetSuite format
        const vendorPayload = mapSheetRowToNetSuiteVendor(rowObj, countryCodesLookup, countryCodes, addressBookLookup, categoriesLookup, currenciesLookup, assaCategoriesLookup, assaNatureLookup, msicLookup, stateCodesLookup);
        
        if (!vendorPayload.entityId && !vendorPayload.legalName) {
          throw new Error('Missing required fields: entityId or legalName');
        }

        if (DEBUG) {
          console.error(`[DEBUG] Full vendor payload: ${JSON.stringify(vendorPayload, null, 2)}`);
          if (vendorPayload.addressBook) {
            console.error(`[DEBUG] Address book country: ${JSON.stringify(vendorPayload.addressBook.items[0].addressBookAddress.country)}`);
          }
          if (vendorPayload.category) {
            console.error(`[DEBUG] Category: ${JSON.stringify(vendorPayload.category)}`);
          }
        }

        // Push to NetSuite
        let res = await upsertVendor(vendorPayload, token);
        
        if (DEBUG && res.data) {
          console.error(`[DEBUG] NetSuite response status: ${res.status}`);
          if (res.status >= 200 && res.status < 300) {
            const vendorId = res.data.id || (res.data.links && res.data.links[0] && res.data.links[0].href ? res.data.links[0].href.match(/\/vendor\/(\d+)/)?.[1] : null);
            console.error(`[DEBUG] Vendor created/updated. Response: ${JSON.stringify(res.data, null, 2).substring(0, 500)}`);
            if (vendorId) {
              console.error(`[DEBUG] Vendor ID: ${vendorId}`);
            }
          } else if (res.status >= 400) {
            console.error(`[DEBUG] NetSuite error response: ${JSON.stringify(res.data, null, 2)}`);
          }
        }
        
        // Retry once if token expired
        if (res.status === 401) {
          console.error('Token expired, refreshing...');
          const newToken = await getValidAccessToken();
          res = await upsertVendor(vendorPayload, newToken);
          if (res.status === 401) {
            throw new Error('Authentication failed after token refresh');
          }
        }

        if (res.status >= 200 && res.status < 300) {
          // Fetch the created/updated vendor to verify what was actually saved
          let vendorId = null;
          if (DEBUG) {
            console.error(`[DEBUG] NetSuite response data: ${JSON.stringify(res.data, null, 2)}`);
          }
          if (res.data && res.data.id) {
            vendorId = res.data.id;
          } else if (res.data && res.data.links && res.data.links[0] && res.data.links[0].href) {
            const match = res.data.links[0].href.match(/\/vendor\/(\d+)/);
            if (match) vendorId = match[1];
          }
          
          if (DEBUG && vendorId) {
            console.error(`[DEBUG] Extracted vendor ID: ${vendorId}`);
            try {
              await new Promise(resolve => setTimeout(resolve, 1000)); // Wait a bit for NetSuite to save
              const verifyUrl = `https://${NETSUITE_DOMAIN}/services/rest/record/v1/vendor/${encodeURIComponent(vendorId)}?expandSubResources=true`;
              const verifyHeaders = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
              const verifyRes = await httpsRequest('GET', verifyUrl, verifyHeaders);
              if (verifyRes.status >= 200 && verifyRes.status < 300 && verifyRes.data) {
                if (verifyRes.data.category) {
                  console.error(`[DEBUG] ✓ Saved vendor category: ${JSON.stringify(verifyRes.data.category)}`);
                } else {
                  console.error('[DEBUG] ✗ Saved vendor category NOT present in response');
                }
                const savedAddressBook = verifyRes.data.addressBook;
                if (savedAddressBook && savedAddressBook.items && savedAddressBook.items[0]) {
                  const savedCountry = savedAddressBook.items[0].addressBookAddress?.country;
                  console.error(`[DEBUG] Saved vendor address book country: ${JSON.stringify(savedCountry)}`);
                } else {
                  console.error(`[DEBUG] No address book found in saved vendor`);
                }
              } else {
                console.error(`[DEBUG] Could not fetch saved vendor: HTTP ${verifyRes.status}`);
                if (DEBUG && verifyRes.data) {
                  console.error(`[DEBUG] Error response: ${JSON.stringify(verifyRes.data)}`);
                }
              }
            } catch (err) {
              if (DEBUG) console.error(`[DEBUG] Could not verify saved vendor: ${err.message}`);
            }
          } else if (DEBUG) {
            console.error(`[DEBUG] Could not extract vendor ID from response`);
          }
          
          console.error(`✓ Successfully synced ${rowKey}`);
          successCount++;
          
          // Move row to Synced sheet
          if (!DRY_RUN) {
            const moved = await moveRowToSyncedSheet(sheets, SHEET_ID, headers, rowObj);
            if (moved) {
              console.error(`  Moved to "${SYNCED_SHEET_NAME}" sheet`);
              // i is the index in the rows array (0-based)
              // rows[0] = header (sheet row 1, API index 0)
              // rows[1] = first data row (sheet row 2, API index 1)
              // So i is already the correct API index for deletion
              rowsToDelete.push(i);
              if (DEBUG) {
                console.error(`  [DEBUG] Added row index ${i} to deletion queue (sheet row ${i + 1})`);
              }
            } else {
              console.error(`  Warning: Failed to move row to "${SYNCED_SHEET_NAME}" sheet, will not delete from source`);
            }
          } else {
            console.error(`  [DRY-RUN] Would move to "${SYNCED_SHEET_NAME}" sheet`);
            rowsToDelete.push(i); // Still track for dry-run logging
          }
        } else {
          const responseData = res.data;
          const errorMsg = typeof responseData === 'string' ? responseData : JSON.stringify(responseData);
          const netSuiteError = new Error(`NetSuite API error: HTTP ${res.status} - ${errorMsg}`);
          netSuiteError.netSuiteResponse = responseData;
          netSuiteError.netSuiteStatus = res.status;
          throw netSuiteError;
        }

        // Small delay to avoid rate limiting
        await new Promise(resolve => setTimeout(resolve, 200));

      } catch (error) {
        errorCount++;
        const errorMsg = error.message || String(error);
        console.error(`✗ Error processing ${rowKey}: ${errorMsg}`);

        let responseStr = '';

        const processNetSuiteResponse = (responseData) => {
          const rawStr = stringifyJsonSafe(responseData);
          const detailStr = extractNetSuiteErrorDetails(responseData);
          if (detailStr) {
            console.error(`[NetSuite Error Details] ${detailStr}`);
            console.error(`[NetSuite Error Response] ${rawStr}`);
            return detailStr;
          }
          console.error(`[NetSuite Error Response] ${rawStr}`);
          return rawStr;
        };

        if (error.netSuiteResponse !== undefined) {
          responseStr = processNetSuiteResponse(error.netSuiteResponse);
        } else if (error.response && error.response.data) {
          responseStr = processNetSuiteResponse(error.response.data);
        }

        if (!responseStr) {
          responseStr = errorMsg;
        }

        errors.push({ row: rowKey, error: errorMsg, data: rowObj, response: responseStr });
      }
    }

    // Delete successfully synced rows from source sheet (in reverse order to maintain indices)
    if (!DRY_RUN && rowsToDelete.length > 0) {
      console.error(`\nDeleting ${rowsToDelete.length} synced rows from source sheet...`);
      console.error(`  Rows to delete (array indices): ${rowsToDelete.join(', ')}`);
      
      const sheetId = await getSheetId(sheets, SHEET_ID, SOURCE_SHEET_NAME);
      // sheetId can be 0 (first sheet), so check for null/undefined specifically
      if (sheetId === null || sheetId === undefined) {
        console.error(`Error: Could not find sheet ID for "${SOURCE_SHEET_NAME}"`);
        try {
          const metadata = await sheets.spreadsheets.get({ spreadsheetId: SHEET_ID });
          const availableSheets = metadata.data.sheets.map(s => s.properties?.title || 'N/A').join(', ');
          console.error(`  Available sheets: ${availableSheets}`);
          console.error(`  Sheet details: ${JSON.stringify(metadata.data.sheets.map(s => ({ title: s.properties?.title, sheetId: s.properties?.sheetId })), null, 2)}`);
        } catch (metaError) {
          console.error(`  Could not fetch sheet metadata: ${metaError.message}`);
        }
        console.error(`  Skipping row deletion - cannot proceed without sheet ID`);
      } else {
        console.error(`  Sheet ID: ${sheetId}`);
        
        // Sort in descending order to delete from bottom to top (maintains correct indices)
        rowsToDelete.sort((a, b) => b - a);
        console.error(`  Sorted rows to delete (descending): ${rowsToDelete.join(', ')}`);
        console.error(`  Note: These are 0-based array indices. Row ${rowsToDelete[0]} = sheet row ${rowsToDelete[0] + 1} (1-indexed)`);
        
        // Delete rows one by one to ensure each deletion is tracked
        let deletedCount = 0;
        for (const rowIndex of rowsToDelete) {
          try {
            // rowIndex is the array index (0-based)
            // rows[0] = header (sheet row 1, API index 0)
            // rows[1] = first data row (sheet row 2, API index 1)
            // So rowIndex is the correct API index for deletion
            const deleteRequest = {
              deleteDimension: {
                range: {
                  sheetId: sheetId,
                  dimension: 'ROWS',
                  startIndex: rowIndex,
                  endIndex: rowIndex + 1
                }
              }
            };
            
            if (DEBUG) {
              console.error(`  [DEBUG] Deleting row index ${rowIndex} (sheet row ${rowIndex + 1}): ${JSON.stringify(deleteRequest)}`);
            }
            
            const result = await sheets.spreadsheets.batchUpdate({
              spreadsheetId: SHEET_ID,
              requestBody: {
                requests: [deleteRequest]
              }
            });
            
            deletedCount++;
            console.error(`  ✓ Deleted row ${rowIndex} (sheet row ${rowIndex + 1})`);
            
            // Small delay to avoid rate limiting
            await new Promise(resolve => setTimeout(resolve, 100));
            
          } catch (error) {
            console.error(`  ✗ Error deleting row ${rowIndex} (sheet row ${rowIndex + 1}): ${error.message}`);
            if (error.response) {
              console.error(`    API Error: ${JSON.stringify(error.response.data, null, 2)}`);
            }
            if (DEBUG) {
              console.error(`    [DEBUG] Full error: ${error.stack}`);
            }
          }
        }
        
        if (deletedCount === rowsToDelete.length) {
          console.error(`  ✓ Successfully deleted all ${deletedCount} rows from source sheet`);
          
          // Verify deletion by reading the sheet again
          try {
            const verifyResponse = await sheets.spreadsheets.values.get({
              spreadsheetId: SHEET_ID,
              range: `${SOURCE_SHEET_NAME}!A:ZZ`,
            });
            const remainingRows = (verifyResponse.data.values || []).length;
            const expectedRows = rows.length - deletedCount; // Original row count minus deleted
            console.error(`  Verification: Sheet now has ${remainingRows} rows (expected: ${expectedRows})`);
            if (remainingRows !== expectedRows) {
              console.error(`  ⚠ Warning: Row count mismatch! Expected ${expectedRows} but found ${remainingRows}`);
            }
          } catch (verifyError) {
            console.error(`  ⚠ Could not verify deletion: ${verifyError.message}`);
          }
        } else {
          console.error(`  ⚠ Only deleted ${deletedCount} out of ${rowsToDelete.length} rows`);
        }
      }
    } else if (DRY_RUN && rowsToDelete.length > 0) {
      console.error(`\n[DRY-RUN] Would delete ${rowsToDelete.length} rows from source sheet`);
      console.error(`  Rows: ${rowsToDelete.join(', ')}`);
    } else if (!DRY_RUN && rowsToDelete.length === 0) {
      console.error(`\nNo rows to delete (none were successfully synced and moved)`);
    }

    // Write errors to Errors sheet
    if (!DRY_RUN && errors.length > 0) {
      console.error(`\nWriting ${errors.length} error(s) to "${ERROR_SHEET_NAME}" sheet...`);
      await writeErrorsToSheet(sheets, SHEET_ID, headers, errors);
    } else if (DRY_RUN && errors.length > 0) {
      console.error(`\n[DRY-RUN] Would write ${errors.length} error(s) to "${ERROR_SHEET_NAME}" sheet`);
    }

    // Summary
    console.error(`\n${'='.repeat(60)}`);
    console.error(`Summary:`);
    console.error(`  Successfully synced: ${successCount}`);
    console.error(`  Errors: ${errorCount}`);
    if (errors.length > 0) {
      console.error(`\nErrors:`);
      errors.forEach(e => {
        console.error(`  - ${e.row}: ${e.error}`);
      });
    }

  } catch (error) {
    console.error('Fatal error:', error.message);
    if (error.response) {
      console.error('API Error:', JSON.stringify(error.response.data, null, 2));
    }
    process.exitCode = 1;
  }
}

main();
