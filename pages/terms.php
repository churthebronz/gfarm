<?php
if (!defined('FastCore')) { exit('Opss!'); }

$siteName = $config->sitename ?? 'GreenFarm';
$opt = array(
    'title'       => $siteName . ' – Terms & Conditions',
    'keywords'    => 'vaultenex, terms, conditions, user agreement, legal',
    'description' => 'Terms & Conditions governing access to and use of ' . $siteName . '.'
);
?>

<!-- PAGE WRAPPER WITH TOP OFFSET SO IT SITS *BELOW* THE FIXED HEADER -->
<div class="vx-legal-page">
  <div class="vx-legal-shell">

    <!-- Header / Title -->
    <header class="vx-legal-header">
      <div class="vx-legal-kicker">Legal</div>
      <h1 class="vx-legal-title">Terms &amp; Conditions</h1>
      <p class="vx-legal-sub">
        These Terms &amp; Conditions form a legally binding agreement between you and
        <strong><?= htmlspecialchars($siteName, ENT_QUOTES); ?></strong>.  
        Please read them carefully before using the website or any related services.
      </p>
      <div class="vx-legal-meta">
        <span class="vx-pill-updated">
          <i class="fa-regular fa-clock"></i>
          Last updated: <strong>December 8, 2025</strong>
        </span>
        <span class="vx-pill-site">
          Applies to: <strong><?= htmlspecialchars($siteName, ENT_QUOTES); ?></strong>
        </span>
      </div>
    </header>

    <!-- Legal body -->
    <section class="vx-legal-card">

      <div class="vx-legal-intro">
        <p>
          These Terms &amp; Conditions (“<strong>Terms</strong>”) apply to your use of the
          <?= htmlspecialchars($siteName, ENT_QUOTES); ?> website and any associated services
          (collectively, the “<strong>Services</strong>”).
        </p>

        <h2 id="acceptance">1. Acceptance of this Agreement</h2>
        <p>
          By accessing, browsing, or using the Services, creating an account, or clicking
          “accept” (or a similar button) where this option is made available, you confirm
          that you have read, understood, and agree to be bound by these Terms.
        </p>
        <p>You represent and warrant that:</p>
        <ul>
          <li>You are at least 18 years of age (or the age of majority in your jurisdiction); and</li>
          <li>You have the legal capacity and authority to enter into this Agreement.</li>
        </ul>
        <p>
          If you are accepting these Terms on behalf of a company or other legal entity,
          you represent that you are authorized to bind that entity, in which case “you”
          and “your” refer to that entity.
        </p>
        <p>
          Once you accept these Terms, your acceptance constitutes an offer to receive
          Services from us. We may conduct additional checks (including identity or
          eligibility checks) and will notify you once your Service has been activated.
        </p>
        <p>
          We may decline to provide Services at our sole discretion. If we decline your
          order after payment has been made, we will refund the amount paid using the
          original payment method as soon as reasonably practicable.
        </p>

        <h2 id="our-agreement">2. Scope of the Agreement</h2>
        <p>
          These Terms, together with any additional policies referenced on the Site,
          govern your relationship with us in relation to the Services. To the extent
          of any inconsistency, these Terms will prevail unless expressly stated otherwise.
        </p>
        <p>
          We may update these Terms from time to time. The most recent version will always
          be available on the Site and will show the “Last updated” date above. By continuing
          to use the Services after changes take effect, you agree to the updated Terms.
        </p>
        <p>
          These Terms are provided in English and that version shall prevail in the event of
          any translation.
        </p>
      </div>

      <ol class="vx-legal-list">

        <!-- 3. INFORMATION ABOUT US -->
        <li id="info-about-us">
          <h3>3. INFORMATION ABOUT US</h3>
          <dl>
            <dt>
              <strong>3.1.</strong>
              We operate the website
              <a href="/" rel="nofollow"><?= htmlspecialchars($siteName, ENT_QUOTES); ?></a>.
              Details on how to contact us are provided on the Site under the
              <strong>Support</strong> or <strong>Contact</strong> sections.
            </dt>
          </dl>
        </li>

        <!-- 4. OUR SERVICES -->
        <li id="our-services">
          <h3>4. OUR SERVICES</h3>
          <dl>
            <dt><strong>4.1.</strong> <u>Seed Plans / Farming Contracts</u>.</dt><br>
            <dd>
              <strong>a.</strong> We provide access to digital “vault” or “plan” products
              which may generate rewards or yield over time based on the specific parameters
              displayed on the Site at the time of purchase.
            </dd>
            <dd>
              <strong>b.</strong> The specific types of plans, their duration, reward rates,
              pricing, and other commercial terms are described on the Site and may be updated
              or withdrawn at any time at our discretion.
            </dd>
            <dd>
              <strong>c.</strong> You must successfully purchase a plan to obtain any related
              rights to use the Services and to accrue associated rewards.
            </dd><br>
          </dl>
        </li>

        <!-- 5. YOUR ACCOUNT -->
        <li id="your-account">
          <h3>5. YOUR ACCOUNT</h3>
          <dl>
            <dt><strong>5.1.</strong> <u>Account Creation</u>.</dt><br>
            <dd>
              To access certain Services, you may be required to create an account on the Site,
              provide a valid email address and a wallet address (e.g., for cryptocurrency payouts),
              and set a secure PIN or password.
            </dd>
            <dt><strong>5.2.</strong> <u>Security</u>.</dt><br>
            <dd>
              You are responsible for maintaining the confidentiality of your login credentials,
              as well as for all activities that occur under your Account. You agree to:
            </dd>
            <dd>
              <strong>a.</strong> Keep your password and/or secure PIN secret and secure; and<br>
              <strong>b.</strong> Notify us promptly if you suspect any unauthorized access or use
              of your Account or credentials.
            </dd>
            <dt><strong>5.3.</strong> <u>Account Actions</u>.</dt><br>
            <dd>
              We may, at our discretion and without liability, suspend, restrict, or close your
              Account if we suspect fraud, unauthorized access, breach of these Terms, or activity
              that we reasonably deem unlawful or harmful.
            </dd><br>
          </dl>
        </li>

        <!-- 6. USE OF THE SITE -->
        <li id="use-of-site">
          <h3>6. USE OF THE SITE</h3>
          <dl>
            <dt>
              Your use of the Site is subject to these Terms and any guidelines or policies
              published on the Site from time to time. You agree to use the Site lawfully,
              not to interfere with its operation, and not to attempt to gain unauthorized
              access to any systems or data.
            </dt><br>
          </dl>
        </li>

        <!-- 7. USE OF OUR SERVICES -->
        <li id="use-of-services">
          <h3>7. USE OF OUR SERVICES</h3>
          <dl>
            <dt><u>Access Requirements</u>.</dt><br>
            <dd>
              Before you can use our Services, you must:
            </dd>
            <dd>
              <strong>a.</strong> Have a valid and active Account;<br>
              <strong>b.</strong> Be approved or verified where required; and<br>
              <strong>c.</strong> Provide any additional information reasonably requested
              (for example, to comply with legal or regulatory obligations).
            </dd><br>
          </dl>
        </li>

        <!-- 8. PRICE OF SERVICES -->
        <li id="price-of-services">
          <h3>8. PRICE OF SERVICES</h3>
          <dl>
            <dt><strong>8.1.</strong> <u>Prices</u>.</dt><br>
            <dd>
              The prices for the Services are as displayed on the Site at the time you submit
              your order. We take reasonable care to ensure that prices are accurate. If we
              discover a pricing error, we may correct it and, where applicable, contact you
              to confirm whether you wish to proceed at the correct price.
            </dd>
            <dt><strong>8.2.</strong> <u>Price Changes</u>.</dt><br>
            <dd>
              We may change prices at any time for new purchases. Changes do not affect plans
              that have already been successfully purchased, unless otherwise expressly stated.
            </dd>
            <dt><strong>8.3.</strong> <u>Taxes</u>.</dt><br>
            <dd>
              Unless expressly stated otherwise on the Site, the price you pay for a plan is
              the final price charged by us. You are solely responsible for any additional tax
              obligations in your jurisdiction (for example, income or capital gains tax).
            </dd><br>
          </dl>
        </li>

        <!-- 9. RESTRICTIONS ON USE -->
        <li id="restrictions-on-use">
          <h3>9. RESTRICTIONS ON USE</h3>
          <dl>
            <dt><u>Our Remedies</u>.</dt><br>
            <dd>
              If we reasonably believe that you (or any entity under your control) have:
            </dd>
            <dd>
              <strong>a.</strong> Breached these Terms or any policy referenced herein;<br>
              <strong>b.</strong> Misused or attempted to misuse the Services or Site;<br>
              <strong>c.</strong> Engaged in fraud, money laundering, or other illegal activity; or<br>
              <strong>d.</strong> Infringed or misappropriated our intellectual property rights or
              confidential information,
            </dd>
            <dd>
              then, without prior notice and without limiting any other rights or remedies
              available to us, we may:
            </dd>
            <dd>
              <strong>i.</strong> Suspend, restrict, or terminate your access to the Site or Services;<br>
              <strong>ii.</strong> Close your Account;<br>
              <strong>iii.</strong> Refuse to provide Services to you in the future; and/or<br>
              <strong>iv.</strong> Take any legal action we consider appropriate.
            </dd><br>
          </dl>
        </li>

        <!-- 10. TECHNOLOGY -->
        <li id="technology">
          <h3>10. TECHNOLOGY</h3>
          <dl>
            <dt><strong>10.1.</strong> <u>Definition</u>.</dt><br>
            <dd>
              “Technology” means all software, code, interfaces, designs, systems, tools,
              and related intellectual property owned by us or our suppliers and used to
              provide the Services.
            </dd>
            <dt><strong>10.2.</strong> <u>Ownership</u>.</dt><br>
            <dd>
              All rights, title, and interest in and to the Technology remain exclusively
              with us or our licensors. No ownership rights are transferred to you under
              these Terms. You receive only a limited, revocable right to access and use
              the Services as expressly permitted.
            </dd>
            <dt><strong>10.3.</strong> <u>Restrictions on Use</u>.</dt><br>
            <dd>
              You may not copy, modify, reverse engineer, decompile, disassemble, or
              attempt to derive source code from the Technology, nor may you create
              derivative works based on it, except where such restrictions are prohibited
              by applicable law.
            </dd><br>
          </dl>
        </li>

        <!-- 11. PERSONAL DATA -->
        <li id="personal-information">
          <h3>11. PERSONAL DATA &amp; PRIVACY</h3>
          <dl>
            <dt>
              We collect and process personal data in accordance with our Privacy Policy
              (as published on the Site). In summary:
            </dt><br>
            <dd>
              <strong>a.</strong> We use the information you provide primarily to operate your Account,
              deliver the Services, communicate with you about service-related matters, and improve
              our offerings.
            </dd>
            <dd>
              <strong>b.</strong> We do not sell your personal data to third parties.
            </dd>
            <dd>
              <strong>c.</strong> We implement reasonable technical and organizational measures
              designed to protect your information; however, no system is completely secure,
              and you acknowledge that you use the Services at your own risk.
            </dd><br>
          </dl>
        </li>

        <!-- 12. RISK DISCLOSURE -->
        <li id="risk-disclosure">
          <h3>12. RISK DISCLOSURE &amp; NO INVESTMENT ADVICE</h3>
          <dl>
            <dt><strong>12.1.</strong> <u>General Risk of Digital Assets</u>.</dt><br>
            <dd>
              Activities involving cryptocurrencies, digital assets, and yield products carry a high
              level of risk and may not be suitable for all users. Prices can be extremely volatile,
              and you may lose some or all of the funds you allocate to any plan.
            </dd>
            <dt><strong>12.2.</strong> <u>No Guarantees</u>.</dt><br>
            <dd>
              Unless explicitly stated in writing, we do not guarantee any particular return,
              reward rate, or outcome. Historical performance does not guarantee future results.
            </dd>
            <dt><strong>12.3.</strong> <u>No Investment or Financial Advice</u>.</dt><br>
            <dd>
              Information provided on the Site or via the Services is for informational and
              educational purposes only and should not be considered investment, financial, tax,
              or legal advice. You are solely responsible for your decisions and should consult
              independent professional advisors where appropriate.
            </dd><br>
          </dl>
        </li>

        <!-- 13. LIABILITY -->
        <li id="liability">
          <h3>13. LIMITATION OF LIABILITY</h3>
          <dl>
            <dt><strong>13.1.</strong> <u>Exclusions</u>.</dt><br>
            <dd>
              Nothing in these Terms excludes or limits our liability for:
            </dd>
            <dd>
              <strong>a.</strong> Death or personal injury caused by our negligence; or<br>
              <strong>b.</strong> Fraud or fraudulent misrepresentation.
            </dd><br>

            <dt><strong>13.2.</strong> <u>Service Providers</u>.</dt><br>
            <dd>
              We may rely on third-party providers (for example, infrastructure or payment
              processors) in connection with the Services. To the fullest extent permitted
              by law, we are not responsible for any loss or damage arising from acts or
              omissions of such third-party providers.
            </dd>

            <dt><strong>13.3.</strong> <u>Liability Cap</u>.</dt><br>
            <dd>
              To the fullest extent permitted by applicable law, our aggregate liability to you
              arising out of or in connection with the Services and these Terms (whether in
              contract, tort, negligence, or otherwise) shall be limited to the total Service
              fees you have paid to us in the 12 months immediately preceding the event giving
              rise to the claim.
            </dd><br>
          </dl>
        </li>

        <!-- 14. OTHER TERMS -->
        <li id="other-terms">
          <h3>14. OTHER IMPORTANT TERMS</h3>
          <dl>
            <dt><strong>14.1.</strong> <u>Entire Agreement</u>.</dt><br>
            <dd>
              These Terms, together with any documents or policies expressly referenced herein,
              constitute the entire agreement between you and us concerning the Services and
              supersede all prior or contemporaneous understandings.
            </dd>

            <dt><strong>14.2.</strong> <u>Assignment by Us</u>.</dt><br>
            <dd>
              We may transfer or assign our rights and obligations under these Terms to another
              entity (for example, in connection with a merger or acquisition). Such assignment
              will not affect your rights under these Terms.
            </dd>

            <dt><strong>14.3.</strong> <u>Assignment by You</u>.</dt><br>
            <dd>
              You may not assign, transfer, or sublicense your rights or obligations under these
              Terms without our prior written consent. Any attempt to do so in violation of this
              clause will be void.
            </dd>

            <dt><strong>14.4.</strong> <u>Third-Party Rights</u>.</dt><br>
            <dd>
              These Terms are for the benefit of you and us only. No third party has any rights
              to enforce any of these Terms.
            </dd>

            <dt><strong>14.5.</strong> <u>Severability</u>.</dt><br>
            <dd>
              If any provision of these Terms is held to be invalid or unenforceable, that
              provision will be applied to the maximum extent permissible, and the remaining
              provisions will remain in full force and effect.
            </dd>

            <dt><strong>14.6.</strong> <u>No Waiver</u>.</dt><br>
            <dd>
              Our failure to enforce any right or provision under these Terms will not be
              considered a waiver of such right or provision.
            </dd>

            <dt><strong>14.7.</strong> <u>Survival</u>.</dt><br>
            <dd>
              Any provisions which by their nature should survive termination of these Terms
              (including, without limitation, provisions relating to intellectual property,
              liability, and dispute resolution) shall continue in full force and effect.
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

/* Force a clean, readable font (desktop + mobile) */
.vx-legal-page,
.vx-legal-page *{
  font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
}

/* PAGE OFFSET so content sits below fixed header */
.vx-legal-page{
  position:relative;
  z-index:1;
  padding: calc(var(--vx-header-offset, 84px)) 12px 40px;
  color:var(--vx-text);
}

/* Main shell */
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
.vx-legal-card h2{
  font-size:1.15rem;
  margin:1.1rem 0 .45rem;
  font-weight:800;
  color:#fff4e6;
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
