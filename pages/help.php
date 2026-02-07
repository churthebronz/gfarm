<?php
if (!defined('FastCore')) { exit('Opss!'); }

$siteName     = $config->sitename ?? 'GreenFarm';
$supportEmail = $config->email    ?? 'support@example.com';
$supportTg    = $config->telegram ?? '@GreenFarmSupport';

$opt = array(
    'title'       => $siteName . ' – Help & Support',
    'description' => 'Support contacts, FAQ, and guidance for using the ' . $siteName . ' vault mining platform.'
);
?>

<div class="vx-help-page">
  <div class="vx-help-shell">

    <!-- Header -->
    <header class="vx-help-header">
      <div class="vx-help-kicker">Support Center</div>
      <h1 class="vx-help-title">Help &amp; Contact</h1>
      <p class="vx-help-sub">
        Need help with vaults, payouts, or your Telegram Mini App access?
        Our team is here to support you every step of the way.
      </p>
      <div class="vx-help-meta">
        <span class="vx-pill">
          <i class="fa-regular fa-clock"></i>
          Typical reply: <strong>within 24 hours</strong>
        </span>
        <span class="vx-pill">
          <i class="fa-brands fa-telegram"></i>
          Primary channel: <strong>Telegram Support</strong>
        </span>
      </div>
    </header>

    <!-- Contact Blocks -->
    <section class="vx-contact-grid">
      <article class="vx-contact-card">
        <div class="vx-contact-icon email">
          <i class="fa-regular fa-envelope"></i>
        </div>
        <div class="vx-contact-body">
          <h3>Email Support</h3>
          <p class="vx-contact-line">
            For detailed questions, account issues, or documentation requests.
          </p>
          <p class="vx-contact-main">
            <a href="mailto:<?= htmlspecialchars($supportEmail, ENT_QUOTES); ?>">
              <?= htmlspecialchars($supportEmail, ENT_QUOTES); ?>
            </a>
          </p>
          <p class="vx-contact-foot">
            Business hours: <strong>Mon–Fri</strong> · Global coverage
          </p>
        </div>
      </article>

      <article class="vx-contact-card">
        <div class="vx-contact-icon tg">
          <i class="fa-brands fa-telegram"></i>
        </div>
        <div class="vx-contact-body">
          <h3>Telegram Support</h3>
          <p class="vx-contact-line">
            Quick questions, vault status checks, or payout follow-ups via Telegram.
          </p>
          <p class="vx-contact-main">
            <a href="https://t.me/<?= ltrim(htmlspecialchars($supportTg, ENT_QUOTES), '@'); ?>" target="_blank" rel="noopener">
              <?= htmlspecialchars($supportTg, ENT_QUOTES); ?>
            </a>
          </p>
          <p class="vx-contact-foot">
            Best for: <strong>Mini App access issues &amp; live assistance</strong>
          </p>
        </div>
      </article>

      <article class="vx-contact-card vx-contact-card-soft">
        <div class="vx-contact-icon guide">
          <i class="fa-solid fa-circle-question"></i>
        </div>
        <div class="vx-contact-body">
          <h3>Before You Ask</h3>
          <p class="vx-contact-line">
            Most common questions around deposits, withdrawals, and vault limits
            are answered in the FAQ below.
          </p>
          <p class="vx-contact-main">
            <a href="#vx-faq" class="vx-link-inline">
              Review the FAQ first
            </a>
          </p>
          <p class="vx-contact-foot">
            It helps us resolve your issue <strong>faster</strong>.
          </p>
        </div>
      </article>
    </section>

    <!-- FAQ -->
    <section class="vx-help-faq" id="vx-faq">
      <div class="vx-faq-head">
        <h2 class="vx-faq-title">Frequently Asked Questions</h2>
        <p class="vx-faq-sub">
          Quick answers for the most common questions about <?= htmlspecialchars($siteName, ENT_QUOTES); ?>,
          vaults, withdrawals, and referrals.
        </p>
      </div>

      <div class="vx-faq-list">

        <!-- 1 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">01</div>
            <div class="vx-faq-text">
              <h3>What is <?= htmlspecialchars($siteName, ENT_QUOTES); ?>?</h3>
              <p>How the vault mining platform works in simple terms.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              <?= htmlspecialchars($siteName, ENT_QUOTES); ?> is a Telegram-integrated vault mining platform.
              You activate vault plans, earn automated daily rewards, and withdraw to your crypto wallet.
              Everything runs through your verified Telegram WebApp session — no separate password needed.
            </p>
          </div>
        </article>

        <!-- 2 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">02</div>
            <div class="vx-faq-text">
              <h3>Do you have a referral or affiliate program?</h3>
              <p>How to earn extra rewards by inviting others.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              Yes. When someone joins through your referral link and activates vaults, you receive
              a <strong>cash commission on deposits</strong> plus additional <strong>Points</strong> based
              on their activity. Both cash and Points appear in your account and contribute to your
              overall earnings and VX token exposure at TGE.
            </p>
          </div>
        </article>

        <!-- 3 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">03</div>
            <div class="vx-faq-text">
              <h3>How long do withdrawals take?</h3>
              <p>Withdrawal processing times and confirmations.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              Withdrawals are typically processed automatically once your request is confirmed.
              Final timing depends on the blockchain network you choose (e.g. BNB, TON, BTC, etc.).
              Most payouts appear after at least one on-chain confirmation.
            </p>
          </div>
        </article>

        <!-- 4 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">04</div>
            <div class="vx-faq-text">
              <h3>Can I withdraw without activating a vault?</h3>
              <p>Minimum requirements to enable withdrawals.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              No. To unlock withdrawals you must first make at least the current minimum deposit
              (for example, <strong>$5 USD equivalent</strong>) and activate a vault plan.
              This ensures the platform remains sustainable and focused on active users.
            </p>
          </div>
        </article>

        <!-- 5 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">05</div>
            <div class="vx-faq-text">
              <h3>When is my deposit credited?</h3>
              <p>What to expect after sending funds.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              Deposits are usually credited automatically after at least one blockchain confirmation.
              Once credited, your balance is updated and you can immediately activate vaults from the
              dashboard or plans page.
            </p>
          </div>
        </article>

        <!-- 6 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">06</div>
            <div class="vx-faq-text">
              <h3>Are there any platform fees?</h3>
              <p>Fee structure and why it exists.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              There may be a small platform fee to keep <?= htmlspecialchars($siteName, ENT_QUOTES); ?> secure,
              maintained, and growing. We aim to keep this transparent and competitive, without hidden
              maintenance or surprise charges. Network fees from the blockchain (gas/transaction fees) are
              separate and depend on the chain you use.
            </p>
          </div>
        </article>

        <!-- 7 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">07</div>
            <div class="vx-faq-text">
              <h3>I can’t access my account. What should I do?</h3>
              <p>Troubleshooting login &amp; Telegram access.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              Access is managed through Telegram WebApp authentication. First, ensure you are logged into the
              same Telegram account you used originally. If you still cannot access your vaults, please contact
              us via <strong>Telegram Support</strong> or email, and include your Telegram username and any
              relevant details so we can verify and assist safely.
            </p>
          </div>
        </article>

        <!-- 8 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">08</div>
            <div class="vx-faq-text">
              <h3>How many vault plans can I activate?</h3>
              <p>Limits per plan and scaling your position.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              For most vault tiers, you can activate up to a defined limit (for example, up to
              <strong>10 vaults per specific plan</strong>). This allows you to scale your exposure while
              keeping the system balanced for all users. Exact limits are shown in your plans dashboard.
            </p>
          </div>
        </article>

        <!-- 9 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">09</div>
            <div class="vx-faq-text">
              <h3>How often can I withdraw?</h3>
              <p>Withdrawal frequency and platform stability.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              To keep the platform stable and predictable, withdrawals may be limited to once every
              <strong>24 hours</strong>. This helps protect liquidity and ensures payouts remain smooth
              for the entire community.
            </p>
          </div>
        </article>

        <!-- 10 -->
        <article class="vx-faq-card" tabindex="0" aria-expanded="false">
          <header class="vx-faq-header">
            <div class="vx-faq-num">10</div>
            <div class="vx-faq-text">
              <h3>What’s the best way to contact support?</h3>
              <p>Choosing between Telegram and email.</p>
            </div>
            <div class="vx-faq-arrow" aria-hidden="true">
              <i class="fa-solid fa-chevron-down"></i>
            </div>
          </header>
          <div class="vx-faq-body">
            <p>
              For fast questions or Telegram Mini App issues, use
              <strong><?= htmlspecialchars($supportTg, ENT_QUOTES); ?></strong> on Telegram.
              For account-specific or more detailed matters, email us at
              <strong><?= htmlspecialchars($supportEmail, ENT_QUOTES); ?></strong>. Please include your
              Telegram username and a clear description of your request.
            </p>
          </div>
        </article>

      </div>
    </section>

  </div>
</div>

<script>
// Simple FAQ accordion (TG-friendly)
document.addEventListener('DOMContentLoaded', function () {
  var cards = document.querySelectorAll('.vx-faq-card');
  cards.forEach(function (card) {
    var header = card.querySelector('.vx-faq-header');
    header.addEventListener('click', toggle);
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggle.call(header);
      }
    });

    function toggle() {
      var open = card.classList.contains('open');
      if (open) {
        card.classList.remove('open');
        card.setAttribute('aria-expanded', 'false');
      } else {
        cards.forEach(function (c) {
          c.classList.remove('open');
          c.setAttribute('aria-expanded', 'false');
        });
        card.classList.add('open');
        card.setAttribute('aria-expanded', 'true');
      }
    }
  });
});
</script>

<style>
:root{
  --vx-amber:#ffae00;
  --vx-orange:#ff6a00;
  --vx-text:#fdf9f3;
  --vx-muted:#cbd5f5;
  --vx-panel:#101622;
  --vx-border:rgba(148,163,184,0.55);
}

/* Ensure readable, clean font */
.vx-help-page,
.vx-help-page *{
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
}

/* Page layout (below fixed header) */
.vx-help-page{
  position:relative;
  z-index:1;
  padding: calc(var(--vx-header-offset, 84px)) 12px 40px;
  color:var(--vx-text);
}
.vx-help-shell{
  max-width:980px;
  margin:0 auto;
}

/* Header */
.vx-help-header{
  margin-bottom:18px;
}
.vx-help-kicker{
  font-size:.85rem;
  text-transform:uppercase;
  letter-spacing:.14em;
  font-weight:800;
  color:#bfdbfe;
  margin-bottom:4px;
}
.vx-help-title{
  margin:0 0 6px;
  font-size:1.8rem;
  font-weight:900;
  letter-spacing:.05em;
  text-transform:uppercase;
  color:#f9fafb;
}
.vx-help-sub{
  margin:0 0 10px;
  font-size:.98rem;
  color:var(--vx-muted);
  line-height:1.6;
}
.vx-help-meta{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
}
.vx-pill{
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-size:.82rem;
  padding:5px 11px;
  border-radius:999px;
  border:1px solid var(--vx-border);
  background:rgba(15,23,42,0.75);
  color:#e2e8f0;
}
.vx-pill i{
  color:#facc15;
}

/* Contact grid */
.vx-contact-grid{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:14px;
  margin-bottom:28px;
}
.vx-contact-card{
  display:flex;
  gap:12px;
  padding:13px 14px;
  border-radius:16px;
  background:radial-gradient(circle at 0 0,rgba(148,163,255,0.18),transparent 55%) ,
             radial-gradient(circle at 100% 100%,rgba(251,191,36,0.18),transparent 55%) ,
             rgba(15,23,42,0.96);
  border:1px solid rgba(148,163,184,0.55);
  box-shadow:0 14px 34px rgba(15,23,42,0.9);
}
.vx-contact-card-soft{
  opacity:.95;
}
.vx-contact-icon{
  flex:0 0 40px;
  height:40px;
  border-radius:14px;
  display:flex;
  align-items:center;
  justify-content:center;
  color:#020617;
  font-size:1.1rem;
  box-shadow:0 0 0 1px rgba(15,23,42,0.9),0 8px 20px rgba(15,23,42,0.9);
}
.vx-contact-icon.email{
  background:radial-gradient(circle at 30% 0,#f9fafb,transparent 55%),
             radial-gradient(circle at 50% 120%,#fbbf24,#f97316);
}
.vx-contact-icon.tg{
  background:radial-gradient(circle at 30% 0,#e0f2fe,transparent 55%),
             radial-gradient(circle at 50% 120%,#38bdf8,#0ea5e9);
}
.vx-contact-icon.guide{
  background:radial-gradient(circle at 30% 0,#fef9c3,transparent 55%),
             radial-gradient(circle at 50% 120%,#a855f7,#f97316);
}
.vx-contact-body h3{
  margin:0 0 4px;
  font-size:1rem;
  font-weight:800;
  color:#f9fafb;
}
.vx-contact-line{
  margin:0 0 4px;
  font-size:.84rem;
  color:#cbd5f5;
}
.vx-contact-main{
  margin:0 0 4px;
  font-size:.9rem;
}
.vx-contact-main a{
  color:#facc15;
  text-decoration:none;
  font-weight:700;
}
.vx-contact-main a:hover{
  text-decoration:underline;
}
.vx-contact-foot{
  margin:0;
  font-size:.78rem;
  color:#94a3b8;
}
.vx-link-inline{
  color:#bfdbfe;
  text-decoration:none;
  font-weight:600;
}
.vx-link-inline:hover{
  text-decoration:underline;
}

/* FAQ section */
.vx-help-faq{
  margin-top:10px;
}
.vx-faq-head{
  text-align:left;
  margin-bottom:12px;
}
.vx-faq-title{
  margin:0 0 4px;
  font-size:1.25rem;
  font-weight:900;
  color:#f9fafb;
}
.vx-faq-sub{
  margin:0;
  font-size:.94rem;
  color:var(--vx-muted);
}

/* FAQ cards */
.vx-faq-list{
  display:flex;
  flex-direction:column;
  gap:8px;
}
.vx-faq-card{
  border-radius:14px;
  background:rgba(15,23,42,0.96);
  border:1px solid rgba(30,64,175,0.55);
  box-shadow:0 12px 28px rgba(15,23,42,0.95);
  cursor:pointer;
  outline:none;
  transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.vx-faq-card.open{
  border-color:rgba(251,191,36,0.9);
  box-shadow:0 14px 34px rgba(251,191,36,0.4);
  background:linear-gradient(180deg,rgba(15,23,42,0.98),rgba(15,23,42,0.95));
}
.vx-faq-header{
  display:flex;
  align-items:flex-start;
  gap:12px;
  padding:10px 12px;
}
.vx-faq-num{
  flex:0 0 32px;
  height:32px;
  border-radius:999px;
  background:rgba(15,23,42,0.98);
  border:1px solid rgba(148,163,184,0.75);
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:.82rem;
  font-weight:800;
  color:#e5e7eb;
}
.vx-faq-text h3{
  margin:0 0 2px;
  font-size:.98rem;
  font-weight:800;
  color:#f9fafb;
}
.vx-faq-text p{
  margin:0;
  font-size:.82rem;
  color:#9ca3af;
}
.vx-faq-arrow{
  margin-left:auto;
  display:flex;
  align-items:center;
  font-size:.8rem;
  color:#e5e7eb;
  transition:transform .18s ease;
}
.vx-faq-card.open .vx-faq-arrow{
  transform:rotate(180deg);
}

/* FAQ body */
.vx-faq-body{
  max-height:0;
  overflow:hidden;
  padding:0 12px;
  border-top:1px solid transparent;
  transition:max-height .18s ease, padding-top .18s ease, padding-bottom .18s ease, border-color .18s ease;
}
.vx-faq-card.open .vx-faq-body{
  border-color:rgba(30,64,175,0.7);
  padding-top:6px;
  padding-bottom:10px;
  max-height:300px;
}
.vx-faq-body p{
  margin:0;
  font-size:.9rem;
  color:#e5e7eb;
  line-height:1.6;
}

/* Focus */
.vx-faq-card:focus-visible{
  box-shadow:0 0 0 2px #fbbf24;
}

/* Responsive */
@media (max-width:900px){
  .vx-contact-grid{
    grid-template-columns:1fr;
  }
}
@media (max-width:768px){
  .vx-help-page{
    padding: calc(var(--vx-header-offset, 76px)) 10px 30px;
  }
  .vx-help-title{
    font-size:1.5rem;
  }
}
</style>

<section class="vx-section" id="launch-notices">
  <div class="vx-section-hd">
    <h2><i class="fa-solid fa-circle-info"></i> Important Notices</h2>
    <p>Read this before using GreenFarm.</p>
  </div>

  <div class="vx-sp-frame" style="padding:14px;border-radius:18px;border:1px solid rgba(148,163,184,.16);background:rgba(2,6,23,.55)">
    <div style="display:grid;gap:10px;color:rgba(226,232,240,.86)">
      <div><b>Not investment advice.</b> GreenFarm is a gamified rewards platform. Nothing here is a solicitation or a guarantee of profit.</div>
      <div><b>No guarantees.</b> Rewards can change, be capped, paused, or adjusted due to market, security, or operational needs.</div>
      <div><b>Eligibility & geography.</b> You are responsible for complying with your local laws, tax obligations, and any restrictions in your jurisdiction.</div>
      <div><b>How rewards work.</b> Rewards are governed by platform rules (vault rates, caps, seasons, and anti-abuse). <b>Caps</b> limit how many activations count during a season. <b>Seasons reset</b> caps, but <b>vault timers never reset</b>.</div>
      <div><b>Anti-abuse.</b> Multi-accounting, automation, or manipulation can result in holds, reversals, or bans.</div>
    </div>
  </div>
</section>
