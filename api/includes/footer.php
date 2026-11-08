<footer class="brand-footer">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="index.php" class="brand-wordmark">looply<span>.</span></a>
        <p>By Students. For Students.</p>
        <p>&copy; <?= date("Y") ?> LOOPLY</p>
    </div>
</footer>
<script>
if ("IntersectionObserver" in window && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    const siteRevealTargets = document.querySelectorAll(
        "main > section:not(.scroll-reveal), main > article:not(.scroll-reveal), main > div.bg-white:not(.scroll-reveal), main > div.rounded-2xl:not(.scroll-reveal), main aside.bg-white:not(.scroll-reveal), main .product-card:not(.scroll-reveal)"
    );
    const siteRevealObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add("site-revealed");
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: "0px 0px -24px 0px" });

    siteRevealTargets.forEach((target, index) => {
        target.classList.add("site-reveal");
        target.style.setProperty("--site-reveal-delay", `${(index % 5) * 55}ms`);
        siteRevealObserver.observe(target);
    });
}
</script>
