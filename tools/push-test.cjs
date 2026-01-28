const webpush = require("web-push");
const Database = require("better-sqlite3");
const path = require("path");

// Mettiamo le chiavi VAPID dalle env
const VAPID_PUBLIC_KEY = process.env.VAPID_PUBLIC_KEY;
const VAPID_PRIVATE_KEY = process.env.VAPID_PRIVATE_KEY;
const VAPID_SUBJECT = process.env.VAPID_SUBJECT || "mailto:info@smartera.com";

if (!VAPID_PUBLIC_KEY || !VAPID_PRIVATE_KEY) {
  console.error("❌ Manca VAPID_PUBLIC_KEY o VAPID_PRIVATE_KEY nelle env.");
  process.exit(1);
}

webpush.setVapidDetails(VAPID_SUBJECT, VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY);

// SQLite DB di Laravel
const dbPath = path.join(__dirname, "..", "database", "database.sqlite");
const db = new Database(dbPath);

const rows = db.prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions").all();
console.log("📌 Subscriptions trovate:", rows.length);

(async () => {
  let ok = 0, fail = 0;

  for (const r of rows) {
    const subscription = {
      endpoint: r.endpoint,
      keys: { p256dh: r.p256dh, auth: r.auth },
    };

    try {
      await webpush.sendNotification(
        subscription,
        JSON.stringify({
          title: "Smart Area Social AI",
          body: "Notifica di test da Node ✅",
          url: "/notifications",
        })
      );
      ok++;
    } catch (e) {
      fail++;
      console.error("❌ Errore invio:", e?.body || e?.message || e);
    }
  }

  console.log("✅ OK:", ok, "❌ FAIL:", fail);
})();
