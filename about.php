<?php
$pageTitle = 'About Us';
require_once __DIR__ . '/templates/header.php';
?>

<style>
    .about-hero { position: relative; overflow: hidden; margin: 1rem 0 5rem; padding: clamp(3rem, 8vw, 6.5rem); border-radius: 30px; color: #fff; background: linear-gradient(135deg, #171934 0%, #303579 55%, #675ce7 100%); }
    .about-hero::after { content: ''; position: absolute; width: 360px; height: 360px; right: -120px; top: -145px; border-radius: 50%; background: rgba(255,255,255,.1); box-shadow: -160px 310px 0 rgba(103,213,200,.12); }
    .about-hero__content { position: relative; z-index: 1; max-width: 760px; }
    .about-hero h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(2.6rem, 6vw, 5rem); line-height: 1.02; letter-spacing: -.055em; margin: 1rem 0 1.4rem; }
    .about-hero p { max-width: 650px; color: rgba(255,255,255,.76); font-size: 1.08rem; line-height: 1.8; }
    .about-section { padding: 1rem 0 5rem; }
    .about-kicker { color: #5b5fe8; font-size: .75rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
    .about-title { max-width: 720px; font-family: 'Space Grotesk', sans-serif; font-size: clamp(2rem, 4vw, 3.35rem); line-height: 1.1; letter-spacing: -.045em; color: #202137; }
    .value-card { height: 100%; padding: 2rem; border: 1px solid #e9e9f4; border-radius: 20px; background: #fff; box-shadow: 0 12px 35px rgba(35,37,82,.06); }
    .value-card__icon { display: grid; place-items: center; width: 50px; height: 50px; margin-bottom: 1.3rem; border-radius: 14px; color: #565add; background: #ededff; }
    .value-card h3 { font-family: 'Space Grotesk', sans-serif; font-size: 1.15rem; }
    .value-card p { margin: 0; color: #707287; line-height: 1.7; }
    .about-panel { overflow: hidden; border-radius: 26px; background: #f0f0ff; }
    .about-panel__copy { padding: clamp(2rem, 6vw, 5rem); }
    .about-panel__visual { min-height: 390px; display: grid; place-items: center; background: linear-gradient(145deg, #5b5fe8, #8c62dc); color: #fff; }
    .about-panel__visual i { font-size: clamp(6rem, 14vw, 11rem); opacity: .9; filter: drop-shadow(0 22px 30px rgba(28,25,82,.25)); }
    .about-stat { padding: 1rem 0; }
    .about-stat strong { display: block; font-family: 'Space Grotesk', sans-serif; font-size: 2rem; color: #242640; }
    .about-stat span { color: #77798c; font-size: .88rem; }
</style>

<div class="container">
    <section class="about-hero">
        <div class="about-hero__content">
            <span class="eyebrow text-white"><i class="fas fa-sparkles"></i> Learning with purpose</span>
            <h1>Practical skills for ambitious people.</h1>
            <p>Umsad Tech brings focused, career-relevant learning closer to students and professionals. We turn complex digital topics into clear lessons, useful practice, and progress you can see.</p>
            <div class="d-flex flex-wrap gap-3 mt-4">
                <a href="<?php echo APP_URL; ?>/courses.php" class="btn btn-light">Explore courses <i class="fas fa-arrow-right ms-2"></i></a>
                <a href="<?php echo APP_URL; ?>/contact.php" class="btn btn-hero-outline">Talk to our team</a>
            </div>
        </div>
    </section>

    <section class="about-section text-center">
        <span class="about-kicker">What guides us</span>
        <h2 class="about-title mx-auto mt-3 mb-5">Education should feel clear, useful, and within reach.</h2>
        <div class="row g-4 text-start">
            <div class="col-md-4">
                <article class="value-card">
                    <span class="value-card__icon"><i class="fas fa-bullseye"></i></span>
                    <h3>Useful from day one</h3>
                    <p>Every course is shaped around skills learners can apply to real work, real projects, and real opportunities.</p>
                </article>
            </div>
            <div class="col-md-4">
                <article class="value-card">
                    <span class="value-card__icon"><i class="fas fa-route"></i></span>
                    <h3>Progress without confusion</h3>
                    <p>Thoughtful course structure and visible milestones help learners know where they are and what comes next.</p>
                </article>
            </div>
            <div class="col-md-4">
                <article class="value-card">
                    <span class="value-card__icon"><i class="fas fa-people-group"></i></span>
                    <h3>Growth is human</h3>
                    <p>We pair technology with approachable guidance, because confidence grows faster when support is close by.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="about-panel mb-5">
        <div class="row g-0 align-items-stretch">
            <div class="col-lg-7">
                <div class="about-panel__copy">
                    <span class="about-kicker">Our direction</span>
                    <h2 class="about-title mt-3">Building a stronger digital future, one learner at a time.</h2>
                    <p class="text-muted mt-4 mb-4" style="line-height:1.8;">Our goal is to make high-quality technology education accessible across Nigeria and beyond. We are creating a learning environment where students can build momentum, instructors can share expertise, and every completed lesson moves someone closer to meaningful work.</p>
                    <div class="row g-3">
                        <div class="col-4 about-stat"><strong>24/7</strong><span>Learn at your pace</span></div>
                        <div class="col-4 about-stat"><strong>100%</strong><span>Online access</span></div>
                        <div class="col-4 about-stat"><strong>1 goal</strong><span>Your growth</span></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 about-panel__visual" aria-hidden="true">
                <i class="fas fa-graduation-cap"></i>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
