<?php
// Only define constants if they haven't been defined already (for Laravel config integration)
if (!defined("NS_ENDPOINT")) {
    define("NS_ENDPOINT", "2025_2");
}
if (!defined("NS_HOST")) {
    define("NS_HOST", "https://9897202-sb1.suitetalk.api.netsuite.com"); // Web Services URL - The service URL can be found in Setup -> Company -> Company Information -> Company URLs under SUITETALK (SOAP AND REST WEB SERVICES). E.g. https://ACCOUNT_ID.suitetalk.api.netsuite.com
}
if (!defined("NS_ACCOUNT")) {
    define("NS_ACCOUNT", "9897202_SB1");
}

// Token Based Authentication data
if (!defined("NS_CONSUMER_KEY")) {
    define("NS_CONSUMER_KEY", "316819912c995b32a095fdd04e41c967be782cacd4deb05af7359cefdb79a2c3"); // Consumer Key shown once on Integration detail page
}
if (!defined("NS_CONSUMER_SECRET")) {
    define("NS_CONSUMER_SECRET", "afe0a509dc75092939b7c0246db2e01597f4975b2faea4e0acd1f0004209d8fe"); // Consumer Secret shown once on Integration detail page
}
// following token has to be for role having those permissions: Log in using Access Tokens, Web Services
if (!defined("NS_TOKEN")) {
    define("NS_TOKEN", "d9e9355604808349a9280db7f424931b93b9d8fd73efaa95e2485a3169f96141"); // Token Id shown once on Access Token detail page
}
if (!defined("NS_TOKEN_SECRET")) {
    define("NS_TOKEN_SECRET", "4aaee4c19ee43a8ffefa8e4426bad1d3ed728ce22209d078f5bf27781640af25"); // Token Secret shown once on Access Token detail page
}

// NETSUITE_DOMAIN=9897202.suitetalk.api.netsuite.com
// SANDBOX_NETSUITE_DOMAIN=9897202-sb1.suitetalk.api.netsuite.com
// NETSUITE_TOKEN_PATH=/services/rest/auth/oauth2/v1/token
// KISSFLOW_BATCH_URL="https://alice-smith.kissflow.com/dataset/2/AcflcLIlo4aq/NetSuite_Vendors/batch"
// KISSFLOW_ACCESS_KEY_ID="Aka0ca96ef-3aa7-4d2d-979b-39abc46de433"
// KISSFLOW_ACCESS_KEY_SECRET="emZhF2r4rOtlnRSX5HvppCg6lMZ609LgAofJz0gKOz8nbcreX2NocVsf81ioFlor9H75cNHuIX5YJ52hGg"

// SANDBOX_MODE=true
// SANDBOX_KISSFLOW_BATCH_URL=https://alice-smith.kissflow.com/dataset/2/AcflcLIlo4aq/NetSuite_Vendors_Sandbox/batch
// CONSUMER_KEY="d0a0a5d58ea71b09a22c3cfe2ef4220cb02047260f98c6b9508258571040d665"
// CONSUMER_SECRET="a436a51466eb07ccfb71a33acf3101eafbeab064064039a1541b38ab0b1a0100"
// # PO_DEFAULT_TAX_CODE_ID=9

// SANDBOX_CONSUMER_KEY="dbc89176d5bab57c8678b2d4e9aab2f5d0ff324370a6e663b75036484638b218"
// SANDBOX_CONSUMER_SECRET="a780e99483369727df4e82dfcc94804537a0171c55410bdda4bb2c6dd207ad23"

?>