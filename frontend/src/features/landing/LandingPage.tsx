import type { RegisterRole } from '../auth/useAuth';

interface PathCard {
  role: RegisterRole;
  icon: string;
  title: string;
  blurb: string;
  cta: string;
}

const PATHS: PathCard[] = [
  {
    role: 'customer',
    icon: '🍔',
    title: 'Order food',
    blurb: 'Browse restaurants near you, build your cart, and track delivery in real time.',
    cta: 'Sign up to order',
  },
  {
    role: 'restaurant_operator',
    icon: '🍽',
    title: 'Add your restaurant',
    blurb: 'List your restaurant, manage your menu, and take orders from a live kitchen queue.',
    cta: 'Register a restaurant',
  },
  {
    role: 'courier',
    icon: '🛵',
    title: 'Deliver with us',
    blurb: 'Pick up nearby orders, deliver on your own schedule, and go online whenever you like.',
    cta: 'Become a courier',
  },
];

const STEPS: { step: string; title: string; blurb: string }[] = [
  { step: '1', title: 'Pick a restaurant', blurb: 'Search local restaurants, explore menus, and add your favourites to the cart.' },
  { step: '2', title: 'Place your order', blurb: 'Check out securely and watch your order move from the kitchen to your door.' },
  { step: '3', title: 'Track to your door', blurb: 'Follow your courier live and get your food hot, fresh, and on time.' },
];

const FEATURES: { icon: string; title: string; blurb: string }[] = [
  { icon: '⚡', title: 'Fast delivery', blurb: 'Smart dispatch pairs every order with the nearest available courier.' },
  { icon: '📍', title: 'Live tracking', blurb: 'Real-time order status and courier location from checkout to delivery.' },
  { icon: '🔒', title: 'Secure checkout', blurb: 'Your payment and personal details are protected on every order.' },
  { icon: '🍴', title: 'Local restaurants', blurb: 'A growing network of restaurants cooking the food you love.' },
];

const CONTACT_EMAIL = 'mtehseenahmed5@gmail.com';
const WHATSAPP_DISPLAY = '+92 331 2251252';
const WHATSAPP_LINK = 'https://wa.me/923312251252';

export function LandingPage({
  onChoose,
  onSignIn,
}: {
  onChoose: (role: RegisterRole) => void;
  onSignIn: () => void;
}) {
  return (
    <div className="landing">
      <header className="landing-topbar">
        <div className="brand">
          <span className="brand-mark">W</span>
          <span>Waselna</span>
        </div>
      </header>

      <section className="landing-hero">
        <h1>Food delivery, done right.</h1>
        <p className="landing-sub">
          Waselna connects hungry customers, local restaurants, and couriers on one platform -
          order, cook, deliver, all in one place.
        </p>
        <div className="landing-hero-actions">
          <button className="btn btn-primary" onClick={() => onChoose('customer')}>
            Order food now
          </button>
          <button className="btn btn-ghost" onClick={onSignIn}>
            I already have an account
          </button>
        </div>
      </section>

      <section className="landing-section">
        <h2 className="landing-section-title">How it works</h2>
        <div className="landing-steps">
          {STEPS.map((s) => (
            <div className="landing-step" key={s.step}>
              <div className="landing-step-num" aria-hidden="true">{s.step}</div>
              <h3>{s.title}</h3>
              <p className="subtle">{s.blurb}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="landing-section landing-features">
        <h2 className="landing-section-title">Why Waselna</h2>
        <div className="landing-feature-grid">
          {FEATURES.map((f) => (
            <div className="landing-feature" key={f.title}>
              <span className="landing-feature-icon" aria-hidden="true">{f.icon}</span>
              <div>
                <h3>{f.title}</h3>
                <p className="subtle">{f.blurb}</p>
              </div>
            </div>
          ))}
        </div>
      </section>

      <section className="landing-section landing-paths">
        <h2 className="landing-section-title">Join Waselna</h2>
        <div className="landing-cards">
          {PATHS.map((p) => (
            <div className="landing-card" key={p.role}>
              <div className="landing-card-icon" aria-hidden="true">{p.icon}</div>
              <h3>{p.title}</h3>
              <p className="subtle">{p.blurb}</p>
              <button className="btn btn-primary btn-block mt-2" onClick={() => onChoose(p.role)}>
                {p.cta}
              </button>
            </div>
          ))}
        </div>
      </section>

      <section className="landing-cta-band">
        <h2>Hungry already?</h2>
        <p className="subtle">Create a free account and order in minutes.</p>
        <button className="btn btn-primary" onClick={() => onChoose('customer')}>
          Get started
        </button>
      </section>

      <footer className="landing-footer">
        <div className="landing-footer-inner">
          <div className="landing-footer-brand">
            <div className="brand">
              <span className="brand-mark">W</span>
              <span>Waselna</span>
            </div>
            <p className="subtle mt-2">Order, cook, deliver - all in one place.</p>
          </div>

          <div className="landing-footer-contact">
            <h4>Contact &amp; support</h4>
            <p className="subtle">Questions, feedback, or partnership enquiries? We would love to hear from you.</p>
            <ul className="landing-contact-list">
              <li>
                <span className="landing-contact-icon" aria-hidden="true">✉️</span>
                <a href={`mailto:${CONTACT_EMAIL}`}>{CONTACT_EMAIL}</a>
              </li>
              <li>
                <span className="landing-contact-icon" aria-hidden="true">💬</span>
                <a href={WHATSAPP_LINK} target="_blank" rel="noopener noreferrer">
                  WhatsApp {WHATSAPP_DISPLAY}
                </a>
              </li>
            </ul>
          </div>
        </div>
        <div className="landing-footer-bottom subtle">
          © {new Date().getFullYear()} Waselna. All rights reserved.
        </div>
      </footer>
    </div>
  );
}