<?php
if (!defined('FastCore')) { exit('Opss!'); }

$siteName = $config->sitename ?? 'GreenFarm';
$contactEmail = $config->email ?? 'support@example.com';

$opt = array(
    'title'       => $siteName . ' – Privacy Policy',
    'keywords'    => 'vaultenex, privacy policy, data protection, gdpr, security',
    'description' => 'Privacy Policy describing how ' . $siteName . ' collects, uses, and protects your personal data.'
);
?>

<div class="vx-legal-page">
  <div class="vx-legal-shell">

    <!-- Header -->
    <header class="vx-legal-header">
      <div class="vx-legal-kicker">Legal</div>
      <h1 class="vx-legal-title">Privacy Policy</h1>
      <p class="vx-legal-sub">
        This Privacy Policy explains how <strong><?= htmlspecialchars($siteName, ENT_QUOTES); ?></strong>
        (“we”, “us”, or “our”) collects, uses, and protects your personal information when you visit our
        website or use our Services.
      </p>
      <div class="vx-legal-meta">
        <span class="vx-pill-updated">
          <i class="fa-regular fa-clock"></i>
          Last updated: <strong>December 8, 2025</strong>
        </span>
        <span class="vx-pill-site">
          Applies to: <strong><?= htmlspecialchars($siteName, ENT_QUOTES); ?></strong> &amp; related Services
        </span>
      </div>
    </header>

    <!-- Legal body -->
    <section class="vx-legal-card">

      <div class="vx-legal-intro">
        <p>
          We are committed to safeguarding your privacy and handling your data in a transparent and
          secure way. This Policy should be read together with our Terms &amp; Conditions and any other
          notices or policies referenced on the Site.
        </p>
        <p>
          By accessing or using the Site or Services, you agree to this Privacy Policy. If you do not
          agree, please do not use the Services.
        </p>
      </div>

      <ol class="vx-legal-list">

        <!-- 1. WHO WE ARE -->
        <li id="who-we-are">
          <h3>1. WHO WE ARE</h3>
          <dl>
            <dt>
              <strong>1.1.</strong> We operate the website
              <a href="/" rel="nofollow"><?= htmlspecialchars($siteName, ENT_QUOTES); ?></a>
              and associated Services, including our Telegram-integrated vault mining platform.
            </dt>
            <dt>
              <strong>1.2.</strong> For questions about this Policy or your data, you can contact us via the
              support options provided on the Site or by email at
              <strong><?= htmlspecialchars($contactEmail, ENT_QUOTES); ?></strong>.
            </dt>
          </dl>
        </li>

        <!-- 2. WHAT DATA WE COLLECT -->
        <li id="data-we-collect">
          <h3>2. INFORMATION WE COLLECT</h3>
          <dl>
            <dt><strong>2.1.</strong> <u>Information you provide directly</u>.</dt><br>
            <dd>
              When you interact with us or use the Services, you may provide:
            </dd>
            <dd>
              <strong>a.</strong> Account details (e.g. username, email address, secure PIN/password);<br>
              <strong>b.</strong> Telegram details when you connect via Telegram (e.g. Telegram user ID, username,
              display name and, where provided, avatar URL);<br>
              <strong>c.</strong> Wallet or payout details (e.g. blockchain addresses for receiving rewards);<br>
              <strong>d.</strong> Support communications (e.g. messages you send to our support team).
            </dd><br>

            <dt><strong>2.2.</strong> <u>Information we collect automatically</u>.</dt><br>
            <dd>
              When you access the Site or Services, we may automatically collect:
            </dd>
            <dd>
              <strong>a.</strong> Technical data (e.g. IP address, browser type, device identifiers, operating system);<br>
              <strong>b.</strong> Usage data (e.g. pages visited, actions taken within the dashboard, timestamps);<br>
              <strong<c.</strong> Session data related to Telegram WebApp sessions for authentication and security.
            </dd><br>

            <dt><strong>2.3.</strong> <u>Transaction &amp; plan data</u>.</dt><br>
            <dd>
              When you use our vault plans or deposit/withdraw funds, we may collect:
            </dd>
            <dd>
              <strong>a.</strong> Plan selections, activation dates, and reward calculations;<br>
              <strong>b.</strong> Deposit and withdrawal amounts and related transaction identifiers;<br>
              <strong>c.</strong> Points balances, referral data, and reward history.
            </dd><br>
          </dl>
        </li>

        <!-- 3. HOW WE USE YOUR INFORMATION -->
        <li id="how-we-use">
          <h3>3. HOW WE USE YOUR INFORMATION</h3>
          <dl>
            <dt>We may process your information for the following purposes:</dt><br>
            <dd>
              <strong>a.</strong> To create, maintain, and secure your account and sessions;
            </dd>
            <dd>
              <strong>b.</strong> To provide the core Services (vault plans, yield calculations, payouts,
              points &amp; referral tracking);
            </dd>
            <dd>
              <strong>c.</strong> To process and confirm transactions;
            </dd>
            <dd>
              <strong>d.</strong> To provide customer support and respond to your requests;
            </dd>
            <dd>
              <strong>e.</strong> To send important service-related messages (e.g. security alerts,
              key updates, policy changes);
            </dd>
            <dd>
              <strong>f.</strong> To send optional marketing or promotional communications, where permitted by law
              and your preferences;
            </dd>
            <dd>
              <strong>g.</strong> To monitor, maintain, and improve the Site and Services, including analytics
              and performance monitoring;
            </dd>
            <dd>
              <strong>h.</strong> To comply with legal obligations and enforce our Terms &amp; Conditions.
            </dd><br>
          </dl>
        </li>

        <!-- 4. LEGAL BASES (where applicable) -->
        <li id="legal-basis">
          <h3>4. LEGAL BASES FOR PROCESSING (WHERE APPLICABLE)</h3>
          <dl>
            <dt>
              Where data protection laws (such as GDPR) apply, we process your personal data based on one or more
              of the following legal bases:
            </dt><br>
            <dd>
              <strong>a.</strong> Performance of a contract – to provide the Services you request and manage your account;
            </dd>
            <dd>
              <strong>b.</strong> Legitimate interests – to operate, secure, and improve our Services, prevent abuse,
              and protect our legal rights;
            </dd>
            <dd>
              <strong>c.</strong> Legal obligation – where we must comply with applicable laws or regulations;
            </dd>
            <dd>
              <strong>d.</strong> Consent – for certain marketing communications or optional features (where required).
            </dd><br>
          </dl>
        </li>

        <!-- 5. COOKIES -->
        <li id="cookies">
          <h3>5. COOKIES &amp; SIMILAR TECHNOLOGIES</h3>
          <dl>
            <dt><strong>5.1.</strong> <u>What are cookies?</u></dt><br>
            <dd>
              Cookies are small text files stored on your device when you visit a website. They help the Site
              remember your preferences and enable certain features.
            </dd>

            <dt><strong>5.2.</strong> <u>How we use cookies</u>.</dt><br>
            <dd>
              We primarily use cookies and similar technologies to:
            </dd>
            <dd>
              <strong>a.</strong> Keep you logged in and manage secure sessions (including Telegram WebApp sessions);<br>
              <strong>b.</strong> Remember basic preferences (such as referral codes and interface choices);<br>
              <strong>c.</strong> Maintain platform security and prevent fraudulent activity;<br>
              <strong>d.</strong> Optionally, perform basic analytics to improve the Service (where permitted).
            </dd>

            <dt><strong>5.3.</strong> <u>Your choices</u>.</dt><br>
            <dd>
              You can adjust your browser settings to block or delete cookies. However, some essential features,
              including login and secure sessions, may not function correctly without necessary cookies.
            </dd><br>
          </dl>
        </li>

        <!-- 6. DATA SHARING -->
        <li id="data-sharing">
          <h3>6. DATA SHARING &amp; DISCLOSURE</h3>
          <dl>
            <dt>
              We do not sell or rent your personal data. We may share information in limited situations:
            </dt><br>
            <dd>
              <strong>a.</strong> <u>Service providers</u> – with trusted third parties who help us operate the
              Services (e.g. infrastructure, payment processors, analytics providers), under appropriate
              confidentiality and data protection obligations;
            </dd>
            <dd>
              <strong>b.</strong> <u>Legal or regulatory requirements</u> – where we are required to do so by law,
              court order, or competent authority;
            </dd>
            <dd>
              <strong>c.</strong> <u>Business transfers</u> – in connection with a merger, acquisition, or sale of
              assets, where your information may be transferred as part of the transaction;
            </dd>
            <dd>
              <strong>d.</strong> <u>Protection of rights</u> – where necessary to protect our rights, property,
              security, or that of our users or the public.
            </dd><br>
          </dl>
        </li>

        <!-- 7. INTERNATIONAL TRANSFERS -->
        <li id="transfers">
          <h3>7. INTERNATIONAL DATA TRANSFERS</h3>
          <dl>
            <dt>
              Because our infrastructure or providers may be located in different countries, your information
              may be transferred and processed outside of your country of residence. We take reasonable steps to
              ensure that such transfers comply with applicable data protection laws and that your data remains
              adequately protected.
            </dt><br>
          </dl>
        </li>

        <!-- 8. DATA SECURITY -->
        <li id="security">
          <h3>8. DATA SECURITY</h3>
          <dl>
            <dt>
              We take security seriously and implement reasonable technical and organizational measures to protect
              your data against unauthorized access, loss, misuse, alteration, or disclosure.
            </dt><br>
            <dd>
              <strong>a.</strong> Access to systems is restricted to authorized personnel with a need to know;<br>
              <strong>b.</strong> Data in transit is protected using technologies such as TLS/SSL where appropriate;<br>
              <strong>c.</strong> Sensitive operations are logged and monitored for suspicious activity.
            </dd>
            <dt>
              However, no online service can be completely secure. You acknowledge that you use the Services at
              your own risk and should adopt appropriate measures to protect your own devices and accounts.
            </dt><br>
          </dl>
        </li>

        <!-- 9. DATA RETENTION -->
        <li id="retention">
          <h3>9. DATA RETENTION</h3>
          <dl>
            <dt>
              We retain your personal data only for as long as necessary to fulfill the purposes described in this
              Policy or as required by law, including:
            </dt><br>
            <dd>
              <strong>a.</strong> For the duration of your active account and your use of the Services;
            </dd>
            <dd>
              <strong>b.</strong> For a reasonable period thereafter to comply with legal, tax, or regulatory
              obligations, resolve disputes, and enforce agreements;
            </dd>
            <dd>
              <strong>c.</strong> For logs and security-related records, for as long as appropriate to maintain the
              integrity and security of the platform.
            </dd><br>
          </dl>
        </li>

        <!-- 10. YOUR RIGHTS -->
        <li id="your-rights">
          <h3>10. YOUR PRIVACY RIGHTS</h3>
          <dl>
            <dt>
              Depending on your location and applicable law (for example, GDPR), you may have some or all of the
              following rights regarding your personal data:
            </dt><br>
            <dd>
              <strong>a. Right to information</strong> – to be informed how your data is processed (this Policy);
            </dd>
            <dd>
              <strong>b. Right of access</strong> – to request confirmation whether we process your data and to
              obtain a copy of such data;
            </dd>
            <dd>
              <strong>c. Right to rectification</strong> – to request correction of inaccurate or incomplete data;
            </dd>
            <dd>
              <strong>d. Right to erasure (“right to be forgotten”)</strong> – to request deletion of your data
              where there is no valid reason for us to keep it (subject to legal obligations and our service needs);
            </dd>
            <dd>
              <strong>e. Right to restrict processing</strong> – to request that we limit how we use your data in
              certain circumstances;
            </dd>
            <dd>
              <strong>f. Right to object</strong> – to object to certain types of processing, including direct
              marketing, where applicable;
            </dd>
            <dd>
              <strong>g. Right to data portability</strong> – to receive certain data in a structured, commonly
              used, machine-readable format and to transmit it to another controller where technically feasible;
            </dd>
            <dd>
              <strong>h. Right to withdraw consent</strong> – where processing is based on your consent, you may
              withdraw that consent at any time (this does not affect processing carried out before withdrawal).
            </dd><br>
            <dt>
              To exercise your rights, you can contact us via the Site or by emailing
              <strong><?= htmlspecialchars($contactEmail, ENT_QUOTES); ?></strong>. We may need to verify your identity
              before responding to your request. We aim to respond within a reasonable time, and within any timeframe
              required by law.
            </dt><br>
          </dl>
        </li>

        <!-- 11. CHILDREN -->
        <li id="children">
          <h3>11. CHILDREN’S PRIVACY</h3>
          <dl>
            <dt>
              Our Services are not directed to, and we do not knowingly collect personal data from, individuals under
              18 years of age (or the age of majority in your jurisdiction). If we become aware that we have collected
              data from a minor without appropriate consent, we will take steps to delete such data.
            </dt><br>
          </dl>
        </li>

        <!-- 12. CHANGES -->
        <li id="changes">
          <h3>12. CHANGES TO THIS PRIVACY POLICY</h3>
          <dl>
            <dt>
              We may update this Privacy Policy from time to time to reflect changes in our practices, technologies,
              or legal requirements. The updated version will be posted on this page with an updated “Last updated”
              date. We encourage you to review this Policy periodically.
            </dt><br>
          </dl>
        </li>

        <!-- 13. CONTACT -->
        <li id="contact">
          <h3>13. CONTACT US</h3>
          <dl>
            <dt>
              If you have any questions, concerns, or requests regarding this Privacy Policy or our handling of your
              personal data, you can contact us by:
            </dt><br>
            <dd>
              <strong>a.</strong> Using the in-app or on-site support options; or<br>
              <strong>b.</strong> Emailing us at
              <strong><?= htmlspecialchars($contactEmail, ENT_QUOTES); ?></strong>.
            </dd><br>
          </dl>
        </li>

      </ol>

    </section>

  </div>
</div>

<style>
:root{
  --vx-amber:#ffae00;
  --vx-orange:#ff6a00;
  --vx-text:#fdf9f3;
  --vx-muted:#d4c8bc;
  --vx-panel:#141016;
  --vx-border:rgba(255,174,0,0.35);
}

/* Force clean, readable font */
.vx-legal-page,
.vx-legal-page *{
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
}

/* PAGE OFFSET so it sits below fixed header */
.vx-legal-page{
  position:relative;
  z-index:1;
  padding: calc(var(--vx-header-offset, 84px)) 12px 40px;
  color:var(--vx-text);
}

.vx-legal-shell{
  max-width:960px;
  margin:0 auto;
}

/* Header */
.vx-legal-header{
  text-align:left;
  margin-bottom:18px;
}
.vx-legal-kicker{
  font-size:.85rem;
  text-transform:uppercase;
  letter-spacing:.14em;
  font-weight:800;
  color:#ffdf7a;
  margin-bottom:4px;
}
.vx-legal-title{
  font-size:1.8rem;
  font-weight:900;
  letter-spacing:.04em;
  text-transform:uppercase;
  color:#fff2dd;
  margin:0 0 6px;
}
.vx-legal-sub{
  margin:0 0 10px;
  font-size:1rem;
  color:var(--vx-muted);
  line-height:1.6;
}
.vx-legal-meta{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
}
.vx-pill-updated,
.vx-pill-site{
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-size:.82rem;
  color:#fef6e4;
  background:#1b151e;
  border-radius:999px;
  border:1px solid var(--vx-border);
  padding:4px 10px;
}
.vx-pill-updated i{color:#ffdf7a;}

/* Card */
.vx-legal-card{
  background:var(--vx-panel);
  border-radius:18px;
  border:1px solid var(--vx-border);
  box-shadow:0 18px 40px rgba(0,0,0,0.75);
  padding:18px 20px 20px;
  font-size:0.98rem;
  line-height:1.7;
}
.vx-legal-card p{
  margin-bottom:.7rem;
}

/* List */
.vx-legal-list{
  margin:12px 0 0;
  padding-left:1.1rem;
}
.vx-legal-list > li{
  margin-bottom:1.25rem;
}
.vx-legal-list > li > h3{
  font-size:1.05rem;
  margin:0 0 .3rem;
  font-weight:800;
  color:#ffdf9f;
  text-transform:uppercase;
  letter-spacing:.05em;
}
.vx-legal-list dl{
  margin:0;
}
.vx-legal-list dt{
  margin-bottom:.45rem;
}
.vx-legal-list dd{
  margin-left:1rem;
  margin-bottom:.3rem;
}

/* Links */
.vx-legal-card a{
  color:#ffdf7a;
  text-decoration:none;
}
.vx-legal-card a:hover{
  text-decoration:underline;
}

/* Mobile tweaks */
@media (max-width:768px){
  .vx-legal-page{
    padding: calc(var(--vx-header-offset, 76px)) 10px 30px;
  }
  .vx-legal-card{
    padding:14px 12px 16px;
    font-size:1rem;
  }
  .vx-legal-title{
    font-size:1.5rem;
  }
  .vx-legal-meta{
    flex-direction:column;
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
