// Checks the shop's email automations (scripts/test-storefront/remote/crm-emails.json) against AnyPress's one
// price list, the API's GET /pricing: every dollar amount in an email must be one of the list's prices, or one of
// the few amounts below that aren't plan prices. Run in CI and every night, so a price changed in the API and not
// in these emails fails here.
import { readFile } from "node:fs/promises";

const PRICING_URL = process.env.PRICING_URL || "https://api.anypress.io/pricing";
const FILE = new URL("./test-storefront/remote/crm-emails.json", import.meta.url);

// Amounts in the emails that aren't plan prices: the affiliate program's $50 payout minimum.
const NOT_PLAN_PRICES = new Set([50]);

const res = await fetch(PRICING_URL, { signal: AbortSignal.timeout(15000) });
if (!res.ok) {
  console.error(`::error::Couldn't read the price list from ${PRICING_URL} (HTTP ${res.status}).`);
  process.exit(1);
}
const list = await res.json();
const prices = new Set([0, ...NOT_PLAN_PRICES]);
for (const type of list.siteTypes) for (const period of ["monthly", "yearly"]) for (const price of Object.values(type[period])) prices.add(price);

const wrong = [];
const walk = (value, where) => {
  if (typeof value === "string") {
    for (const m of value.matchAll(/\$\s?(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d{2}))?/g)) {
      const amount = Number(m[1].replaceAll(",", "")) + Number(m[2] ?? 0) / 100;
      if (!prices.has(amount)) wrong.push(`${where}: ${m[0]}`);
    }
  } else if (value && typeof value === "object") {
    for (const [key, child] of Object.entries(value)) walk(child, `${where}.${key}`);
  }
};
walk(JSON.parse(await readFile(FILE, "utf8")), "crm-emails.json");

if (wrong.length) {
  console.error(`::error::These email prices aren't in the price list (${PRICING_URL}):\n${wrong.join("\n")}`);
  process.exit(1);
}
console.log("Every price in crm-emails.json is in the price list.");
