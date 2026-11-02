// Eco-wealth landing page behaviour, moved out of index.html.

(() => {
  const doc = document;
  const $ = (selector, root = doc) => root.querySelector(selector);
  const $$ = (selector, root = doc) => [
    ...root.querySelectorAll(selector),
  ];
  const reducedMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)",
  ).matches;

  // Slowly and independently shift orbit-dot hues within each Eco-wealth color family.
  // Each dot gets its own randomized first delay and a longer randomized interval thereafter.
  if (!reducedMotion) {
    const orbitPalettes = {
      leaf: [
        ["#78a65a", "rgba(119,169,75,.15)", "rgba(119,169,75,.38)"],
        ["#7fa568", "rgba(127,165,104,.15)", "rgba(127,165,104,.36)"],
        ["#80a875", "rgba(128,168,117,.14)", "rgba(128,168,117,.35)"],
        ["#729d61", "rgba(114,157,97,.15)", "rgba(114,157,97,.37)"],
      ],
      gold: [
        ["#c6a365", "rgba(199,161,95,.17)", "rgba(199,161,95,.40)"],
        ["#c9a873", "rgba(201,168,115,.16)", "rgba(201,168,115,.38)"],
        ["#bea06a", "rgba(190,160,106,.16)", "rgba(190,160,106,.38)"],
        ["#c6aa7c", "rgba(198,170,124,.15)", "rgba(198,170,124,.36)"],
      ],
      blue: [
        ["#568fa7", "rgba(23,107,145,.14)", "rgba(23,107,145,.38)"],
        ["#5c91a5", "rgba(92,145,165,.14)", "rgba(92,145,165,.36)"],
        ["#6597a9", "rgba(101,151,169,.13)", "rgba(101,151,169,.35)"],
        ["#5389a2", "rgba(83,137,162,.14)", "rgba(83,137,162,.36)"],
      ],
    };
    $$(".hero-art .orbit-dot").forEach((dot) => {
      const orbit = dot.closest(".art-orbit");
      const family = orbit?.classList.contains("two")
        ? "gold"
        : orbit?.classList.contains("circle")
          ? "blue"
          : "leaf";
      const palette = orbitPalettes[family];
      let current = 0;
      const applyRandomColor = () => {
        let next = Math.floor(Math.random() * palette.length);
        if (palette.length > 1 && next === current)
          next =
            (next +
              1 +
              Math.floor(Math.random() * (palette.length - 1))) %
            palette.length;
        current = next;
        const [color, ring, glow] = palette[current];
        dot.style.setProperty("--orbit-color", color);
        dot.style.setProperty("--orbit-ring", ring);
        dot.style.setProperty("--orbit-glow", glow);
      };
      const scheduleNext = (initial = false) => {
        // Initial shift: 24–48 seconds. Later shifts: 42–86 seconds, independently randomized.
        const delay = initial
          ? 24000 + Math.random() * 24000
          : 42000 + Math.random() * 44000;
        window.setTimeout(() => {
          if (!doc.hidden) applyRandomColor();
          scheduleNext(false);
        }, delay);
      };
      scheduleNext(true);
    });
  }

  const header = $("#siteHeader");
  const progress = $("#scrollProgress");
  const toTop = $("#toTop");
  const mobileCta = $("#mobileCta");
  const heroActions = $(".hero-actions");
  let scrollTicking = false;

  function updateScrollUI() {
    const root = doc.documentElement;
    const maxScroll = root.scrollHeight - root.clientHeight;
    const amount = maxScroll > 0 ? window.scrollY / maxScroll : 0;
    if (progress) progress.style.transform = `scaleX(${amount})`;
    header?.classList.toggle("is-scrolled", window.scrollY > 12);
    toTop?.classList.toggle("visible", window.scrollY > 650);
    if (!scrollTicking) {
      scrollTicking = true;
      requestAnimationFrame(() => {
        scrollTicking = false;
        if (progress) progress.style.transform = `scaleX(${amount})`;
      });
    }
  }
  window.addEventListener("scroll", updateScrollUI, { passive: true });
  window.addEventListener("resize", updateScrollUI, { passive: true });
  updateScrollUI();

  // Accessible off-canvas navigation: Escape, backdrop, focus return and scroll lock.
  const menuToggle = $("#menuToggle");
  const mobileNav = $("#mobileNav");
  const navScrim = $("#navScrim");
  const closeButton = $("#mobileNavClose");
  let menuOpen = false;
  function setMenu(open, restoreFocus = false) {
    menuOpen = open;
    mobileNav?.classList.toggle("open", open);
    navScrim?.classList.toggle("open", open);
    mobileNav?.setAttribute("aria-hidden", String(!open));
    if (mobileNav) mobileNav.inert = !open;
    navScrim?.setAttribute("aria-hidden", String(!open));
    menuToggle?.setAttribute("aria-expanded", String(open));
    menuToggle?.setAttribute(
      "aria-label",
      open ? "Close navigation" : "Open navigation",
    );
    doc.documentElement.classList.toggle("nav-open", open);
    if (open)
      window.setTimeout(
        () => $(".mobile-nav-links a", mobileNav)?.focus(),
        220,
      );
    else if (restoreFocus) menuToggle?.focus();
  }
  menuToggle?.addEventListener("click", () => setMenu(!menuOpen));
  closeButton?.addEventListener("click", () => setMenu(false, true));
  navScrim?.addEventListener("click", () => setMenu(false, true));
  $$(".mobile-nav-links a, .mobile-nav-foot a", mobileNav).forEach(
    (link) => link.addEventListener("click", () => setMenu(false)),
  );
  doc.addEventListener("keydown", (event) => {
    if (!menuOpen) return;
    if (event.key === "Escape") {
      event.preventDefault();
      setMenu(false, true);
      return;
    }
    if (event.key === "Tab" && mobileNav) {
      const focusable = $$(
        "a[href], button:not([disabled])",
        mobileNav,
      ).filter((element) => element.getClientRects().length > 0);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && doc.activeElement === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && doc.activeElement === last) {
        event.preventDefault();
        first?.focus();
      }
    }
  });
  window.addEventListener("resize", () => {
    if (menuOpen && window.innerWidth > 820) setMenu(false);
  });

  // Reveal content only when it approaches the viewport. Content remains visible without IO support.
  const revealItems = $$("[data-reveal]");
  if ("IntersectionObserver" in window && !reducedMotion) {
    const revealObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-in");
            revealObserver.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -7% 0px" },
    );
    revealItems.forEach((item) => revealObserver.observe(item));
  } else {
    revealItems.forEach((item) => item.classList.add("is-in"));
  }

  // Active section state in the desktop navigation.
  const navLinks = $$('#desktopNav a[href^="#"]');
  const sections = [
    "vision",
    "mission",
    "products",
    "community",
    "preview",
  ]
    .map((id) => doc.getElementById(id))
    .filter(Boolean);
  if ("IntersectionObserver" in window) {
    const sectionObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          navLinks.forEach((link) =>
            link.classList.toggle(
              "is-active",
              link.getAttribute("href") === `#${entry.target.id}`,
            ),
          );
        });
      },
      { rootMargin: "-34% 0px -57% 0px" },
    );
    sections.forEach((section) => sectionObserver.observe(section));
  }

  // Subtle pointer parallax on fine-pointer devices only.
  const hero = $("#heroArt");
  if (
    hero &&
    !reducedMotion &&
    window.matchMedia("(pointer:fine)").matches
  ) {
    let targetX = 0,
      targetY = 0,
      currentX = 0,
      currentY = 0,
      running = true;
    const onMove = (event) => {
      const rect = hero.getBoundingClientRect();
      targetX = Math.max(
        -1,
        Math.min(1, ((event.clientX - rect.left) / rect.width - 0.5) * 2),
      );
      targetY = Math.max(
        -1,
        Math.min(1, ((event.clientY - rect.top) / rect.height - 0.5) * 2),
      );
    };
    const onLeave = () => {
      targetX = 0;
      targetY = 0;
    };
    hero.addEventListener("pointermove", onMove);
    hero.addEventListener("pointerleave", onLeave);
    const animate = () => {
      if (!running || doc.hidden) return;
      currentX += (targetX - currentX) * 0.065;
      currentY += (targetY - currentY) * 0.065;
      hero.style.setProperty("--mx", `${(currentX * 10).toFixed(2)}px`);
      hero.style.setProperty("--my", `${(currentY * 8).toFixed(2)}px`);
      requestAnimationFrame(animate);
    };
    requestAnimationFrame(animate);
    doc.addEventListener("visibilitychange", () => {
      running = !doc.hidden;
      if (running) requestAnimationFrame(animate);
    });
  }

  // Rotating product showcases. One real pack at a time rises in front of an
  // illustration hub, stays long enough to be recognised, leaves, and makes way
  // for another random product. Each pack is measured against the widgets around
  // the hub, so it never crosses them.
  const PRODUCT_SHOTS = [
    { src: "assets/img/products/purple-coffee.png", name: "Purple Coffee" },
    {
      src: "assets/img/products/collagen-coffee.png",
      name: "Collagen Coffee",
    },
    { src: "assets/img/products/insulin-coffee.png", name: "Insulin Coffee" },
    { src: "assets/img/products/immune-booster.png", name: "Immune Booster" },
    { src: "assets/img/products/vitamin-c.png", name: "Vitamin C" },
    { src: "assets/img/products/spirulina.png", name: "Spirulina" },
    { src: "assets/img/products/eye-drops.png", name: "Eye Drops" },
    { src: "assets/img/products/stone-buster.png", name: "Stone Buster" },
  ];
  const SHOT_HOLD_MS = 7000; // fully visible: long enough to read the pack
  const SHOT_LEAVE_MS = 620; // matches the .is-leaving transition
  const SHOT_GAP_MS = 1500; // quiet beat before the next pack arrives
  const SHOT_FLOAT_LIFT = 11; // px the hero widgets rise on their float loop
  const SHOT_CLEARANCE_MAX = 18; // space kept around a pack beside large widgets
  const SHOT_CLEARANCE_MIN = 10; // ...and beside compact ones on phones
  const SHOT_EDGE_PAD = 10; // px free inside the illustration frame
  const SHOT_MAX_HEIGHT = 0.58; // share of the illustration height
  const SHOT_MAX_WIDTH = 0.5; // share of the illustration width
  const SHOT_MAX_SHIFT = 0.07; // how far a pack may slide sideways off centre
  const SHOT_SHIFT_STEPS = 10;
  const SHOT_CENTRE_BIAS = 1.15; // only slide when it buys a 15% bigger pack
  const SHOT_COMFORT_HEIGHT = 0.4; // cramped arts fall back to this share
  const SHOT_COMFORT_WIDTH = 0.42;

  const shotCache = new Map();
  function preloadShot(src) {
    let entry = shotCache.get(src);
    if (!entry) {
      const image = new Image();
      image.decoding = "async";
      image.src = src;
      const ready =
        typeof image.decode === "function"
          ? image.decode().catch(() => {})
          : Promise.resolve();
      entry = { image, ready };
      shotCache.set(src, entry);
    }
    return entry;
  }

  function createProductShowcase(options) {
    const {
      art,
      stage,
      shot,
      widgetSelector,
      startDelay = 1500,
      minHeight = 0,
      comfortFallback = false,
      widgetLift = 0,
      clearanceScale = 0.26,
      lazy = false,
      onShow,
      onHide,
    } = options;

    // Static widget geometry: offsetLeft/Top ignore any floating animation.
    function measureArt() {
      const width = art.clientWidth;
      const height = art.clientHeight;
      const widgets = $$(widgetSelector, art).map((widget) => ({
        left: widget.offsetLeft,
        top: widget.offsetTop,
        right: widget.offsetLeft + widget.offsetWidth,
        bottom: widget.offsetTop + widget.offsetHeight,
      }));
      // The breathing room scales with the widgets, so phones stay tidy too.
      const clearance = widgets.length
        ? Math.min(
            SHOT_CLEARANCE_MAX,
            Math.max(
              SHOT_CLEARANCE_MIN,
              (widgets[0].bottom - widgets[0].top) * clearanceScale,
            ),
          )
        : SHOT_CLEARANCE_MIN;
      return {
        width,
        height,
        cx: width / 2,
        cy: height / 2,
        widgets,
        clearance,
      };
    }

    function clearsWidgets(halfWidth, halfHeight, cx, metrics) {
      if (
        halfWidth > metrics.width / 2 - SHOT_EDGE_PAD ||
        halfHeight > metrics.height / 2 - SHOT_EDGE_PAD
      )
        return false;
      return !metrics.widgets.some((widget) => {
        // A widget sitting below the middle may drift up towards the pack.
        const top =
          widget.top -
          metrics.clearance -
          (widgetLift && widget.top >= metrics.cy ? widgetLift : 0);
        return (
          cx - halfWidth < widget.right + metrics.clearance &&
          cx + halfWidth > widget.left - metrics.clearance &&
          metrics.cy - halfHeight < widget.bottom + metrics.clearance &&
          metrics.cy + halfHeight > top
        );
      });
    }

    // Largest scale that clears every widget when the pack is nudged `shift` sideways.
    function clearScale(naturalWidth, naturalHeight, shift, metrics) {
      const cx = metrics.cx + shift;
      let low = 0;
      let high = Math.min(
        (metrics.height * SHOT_MAX_HEIGHT) / naturalHeight,
        (metrics.width * SHOT_MAX_WIDTH) / naturalWidth,
      );
      if (!(high > 0)) return 0;
      if (
        clearsWidgets(
          (naturalWidth * high) / 2,
          (naturalHeight * high) / 2,
          cx,
          metrics,
        )
      )
        return high;
      for (let step = 0; step < 22; step += 1) {
        const mid = (low + high) / 2;
        if (
          clearsWidgets(
            (naturalWidth * mid) / 2,
            (naturalHeight * mid) / 2,
            cx,
            metrics,
          )
        )
          low = mid;
        else high = mid;
      }
      return low;
    }

    function overlapArea(halfWidth, halfHeight, cx, metrics, pad) {
      return metrics.widgets.reduce((total, widget) => {
        const x =
          Math.min(cx + halfWidth, widget.right + pad) -
          Math.max(cx - halfWidth, widget.left - pad);
        const y =
          Math.min(metrics.cy + halfHeight, widget.bottom + pad) -
          Math.max(metrics.cy - halfHeight, widget.top - pad);
        return total + Math.max(0, x) * Math.max(0, y);
      }, 0);
    }

    function sizeStage(naturalWidth, naturalHeight) {
      if (!naturalWidth || !naturalHeight) return;
      const metrics = measureArt();
      const reach = metrics.width * SHOT_MAX_SHIFT;
      const shifts = [0];
      for (let step = 1; step <= SHOT_SHIFT_STEPS; step += 1) {
        const offset = (reach * step) / SHOT_SHIFT_STEPS;
        shifts.push(-offset, offset);
      }

      let scale = 0;
      let shift = 0;
      shifts.forEach((offset) => {
        const candidate = clearScale(
          naturalWidth,
          naturalHeight,
          offset,
          metrics,
        );
        // Keep the pack centred unless sliding opens up a noticeably bigger one.
        if (candidate > (scale === 0 ? 0 : scale * SHOT_CENTRE_BIAS)) {
          scale = candidate;
          shift = offset;
        }
      });

      if (comfortFallback && naturalHeight * scale < minHeight) {
        // Cramped hero layouts: favour a recognisable pack, then slide it to the
        // quietest spot. The widgets keep the higher stacking level regardless.
        scale = Math.min(
          (metrics.height * SHOT_COMFORT_HEIGHT) / naturalHeight,
          (metrics.width * SHOT_COMFORT_WIDTH) / naturalWidth,
        );
        let least = Infinity;
        shifts.forEach((offset) => {
          const score = overlapArea(
            (naturalWidth * scale) / 2,
            (naturalHeight * scale) / 2,
            metrics.cx + offset,
            metrics,
            SHOT_EDGE_PAD,
          );
          if (score < least - 1) {
            least = score;
            shift = offset;
          }
        });
      }

      stage.style.width = `${Math.round(naturalWidth * scale)}px`;
      stage.style.height = `${Math.round(naturalHeight * scale)}px`;
      stage.style.setProperty("--shift", `${shift.toFixed(1)}px`);
    }

    // Draw from a shuffled bag so every pack is shown before any repeats.
    let bag = [];
    let lastIndex = -1;
    function nextIndex() {
      if (!bag.length) {
        bag = PRODUCT_SHOTS.map((_, index) => index);
        for (let i = bag.length - 1; i > 0; i -= 1) {
          const j = Math.floor(Math.random() * (i + 1));
          [bag[i], bag[j]] = [bag[j], bag[i]];
        }
        if (bag.length > 1 && bag[bag.length - 1] === lastIndex) {
          [bag[bag.length - 1], bag[0]] = [bag[0], bag[bag.length - 1]];
        }
      }
      lastIndex = bag.pop();
      return lastIndex;
    }

    let timer = 0;
    let showing = false;
    let running = false;
    let paused = doc.hidden;
    let prepared = null;
    const schedule = (callback, delay) => {
      window.clearTimeout(timer);
      timer = window.setTimeout(callback, delay);
    };

    // Pick the next pack and start decoding it ahead of its turn.
    function prepare() {
      const index = nextIndex();
      prepared = {
        src: PRODUCT_SHOTS[index].src,
        entry: preloadShot(PRODUCT_SHOTS[index].src),
      };
      return prepared;
    }

    function show() {
      if (!running) return;
      const { src, entry } = prepared || prepare();
      prepared = null;
      entry.ready.then(() => {
        if (!running || paused) return;
        if (!entry.image.naturalWidth) {
          schedule(show, SHOT_GAP_MS);
          return;
        }
        sizeStage(entry.image.naturalWidth, entry.image.naturalHeight);
        shot.src = src;
        stage.classList.remove("is-leaving");
        // Flush layout so the entrance transition restarts even on a cached image.
        void stage.offsetWidth;
        stage.classList.add("is-visible");
        showing = true;
        if (onShow) onShow();
        schedule(dismiss, SHOT_HOLD_MS);
        // Decode the next pack while this one is on screen.
        prepare();
      });
    }

    function hide() {
      stage.classList.remove("is-visible");
      stage.classList.add("is-leaving");
      showing = false;
      if (onHide) onHide();
    }

    function dismiss() {
      hide();
      schedule(() => {
        if (!running || paused) return;
        stage.classList.remove("is-leaving");
        void stage.offsetWidth;
        show();
      }, SHOT_LEAVE_MS + SHOT_GAP_MS);
    }

    function start() {
      if (running) return;
      running = true;
      stage.classList.remove("is-leaving");
      if (!prepared) prepare();
      schedule(show, startDelay);
    }

    function stop() {
      running = false;
      window.clearTimeout(timer);
      timer = 0;
      if (showing) hide();
    }

    // Re-measure when the art resizes so a pack never drifts into a widget.
    if ("ResizeObserver" in window) {
      let resizeFrame = 0;
      new ResizeObserver(() => {
        if (!showing) return;
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = window.requestAnimationFrame(() =>
          sizeStage(shot.naturalWidth, shot.naturalHeight),
        );
      }).observe(art);
    } else {
      window.addEventListener("resize", () => {
        if (showing) sizeStage(shot.naturalWidth, shot.naturalHeight);
      });
    }

    // Pause the rotation while the tab is hidden, then resume with a fresh pack.
    doc.addEventListener("visibilitychange", () => {
      if (doc.hidden) {
        paused = true;
        window.clearTimeout(timer);
        timer = 0;
        if (showing) hide();
        return;
      }
      if (!paused) return;
      paused = false;
      if (!running) return;
      schedule(show, SHOT_GAP_MS);
    });

    if (!lazy) start();

    return { start, stop };
  }

  // Hero: packs rise in front of the leaf, clear of the three pillar widgets.
  const heroShowcase = $("#productShowcase");
  const heroShot = $("#productShot");
  if (heroShowcase && heroShot && hero) {
    createProductShowcase({
      art: hero,
      stage: heroShowcase,
      shot: heroShot,
      widgetSelector: ".float-card",
      startDelay: 1500,
      minHeight: 118,
      comfortFallback: true,
      widgetLift: SHOT_FLOAT_LIFT,
    });
  }

  // Product participation: packs rise in front of the PRODUCT hub, inside the four
  // nodes, and only while the diagram is on screen.
  const foundationCanvas = $("#foundationArt");
  const foundationStage = $("#foundationShowcase");
  const foundationShot = $("#foundationShot");
  if (foundationCanvas && foundationStage && foundationShot) {
    const showcase = createProductShowcase({
      art: foundationCanvas,
      stage: foundationStage,
      shot: foundationShot,
      widgetSelector: ".foundation-node",
      startDelay: 900,
      clearanceScale: 0.16,
      lazy: true,
      onShow: () => foundationCanvas.classList.add("is-showcasing"),
      onHide: () => foundationCanvas.classList.remove("is-showcasing"),
    });
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(
        (entries) => {
          if (entries.some((entry) => entry.isIntersecting))
            showcase.start();
          else showcase.stop();
        },
        { threshold: 0.25 },
      ).observe(foundationCanvas);
    } else {
      showcase.start();
    }
  }

  // Vision rings: broken circles breathe around the leaf, the outermost one
  // dissolves into smoke, and the surviving rings shift outward to fill the gap.
  const visionArt = $(".vision-art");
  const visionRings = $("#visionRings");
  if (visionArt && visionRings) {
    const SVG_NS = "http://www.w3.org/2000/svg";
    const RING_SLOTS = [0.21, 0.265, 0.32, 0.38]; // share of the shorter side
    const RING_COLORS = [
      "rgba(41, 107, 64, 0.42)",
      "rgba(23, 107, 145, 0.38)",
      "rgba(199, 161, 95, 0.5)",
      "rgba(41, 107, 64, 0.36)",
    ];
    const CYCLE_MS = 10000; // one full breathe of the ring set
    const DISSOLVE_MS = 900; // outer ring fading into smoke
    const SHIFT_MS = 1150; // matches the .vision-ring transform transition
    const SHIFT_STEP_MS = 90;
    const smoke = document.createElementNS(SVG_NS, "g");
    smoke.setAttribute("class", "vision-smoke");
    visionRings.appendChild(smoke);

    const rings = [];
    const timers = new Set();
    let metrics = null;
    let running = false;

    const radiusFor = (slot) => metrics.base * RING_SLOTS[slot];

    // Delay helper whose pending work is cancelled whenever the section leaves.
    function later(callback, delay) {
      const id = window.setTimeout(() => {
        timers.delete(id);
        callback();
      }, delay);
      timers.add(id);
      return id;
    }

    function clearTimers() {
      timers.forEach((id) => window.clearTimeout(id));
      timers.clear();
    }

    function createRing(index) {
      const wrap = document.createElementNS(SVG_NS, "g");
      wrap.setAttribute("transform", `translate(${metrics.cx} ${metrics.cy})`);
      const group = document.createElementNS(SVG_NS, "g");
      group.setAttribute("class", "vision-ring");
      const circle = document.createElementNS(SVG_NS, "circle");
      circle.setAttribute("r", "100");
      circle.setAttribute("pathLength", "100");
      circle.setAttribute("stroke", RING_COLORS[index % RING_COLORS.length]);
      // Each ring gets its own rhythm so the set never pulses in lockstep.
      const broken = `${(1.6 + Math.random() * 3.4).toFixed(1)} ${(5 + Math.random() * 6).toFixed(1)}`;
      const closed =
        Math.random() < 0.5
          ? `${(28 + Math.random() * 12).toFixed(0)} 0`
          : `${(16 + Math.random() * 8).toFixed(0)} ${(1 + Math.random() * 3).toFixed(1)}`;
      group.style.setProperty("--ring-broken", broken);
      group.style.setProperty("--ring-closed", closed);
      group.style.setProperty("--ring-dur", `${(5.5 + Math.random() * 4).toFixed(2)}s`);
      group.style.setProperty("--ring-delay", `${(-Math.random() * 7).toFixed(2)}s`);
      group.style.setProperty("--ring-drift", `${(Math.random() * 9).toFixed(1)}`);
      circle.style.strokeDasharray = broken;
      group.appendChild(circle);
      wrap.appendChild(group);
      visionRings.insertBefore(wrap, smoke);
      return { wrap, group, circle, broken, slot: 0 };
    }

    function setSlot(ring, slot, instant) {
      ring.slot = slot;
      const scale = radiusFor(slot) / 100;
      if (instant) ring.group.style.transition = "none";
      ring.group.style.transform = `scale(${scale.toFixed(4)})`;
      if (instant) {
        // Flush the instant placement before transitions are re-enabled.
        void ring.group.getBoundingClientRect();
        ring.group.style.transition = "";
      }
    }

    function build() {
      const width = visionArt.clientWidth;
      const height = visionArt.clientHeight;
      if (!width || !height) return;
      metrics = {
        width,
        height,
        cx: width / 2,
        cy: height * 0.44,
        base: Math.min(width, height),
      };
      visionRings.setAttribute("viewBox", `0 0 ${width} ${height}`);
      rings.forEach((ring) => ring.wrap.remove());
      rings.length = 0;
      smoke.replaceChildren();
      RING_SLOTS.forEach((slot, index) => {
        const ring = createRing(index);
        rings.push(ring);
        setSlot(ring, index, true);
      });
    }

    function resetRings() {
      [...rings]
        .sort((a, b) => a.slot - b.slot)
        .forEach((ring, index) => {
          ring.group.style.opacity = "1";
          setSlot(ring, index, true);
        });
    }

    // A dissolving ring lets go of its line: the dashes thin out as it fades.
    function dissolveRing(ring) {
      const { circle, group } = ring;
      circle.style.animation = "none";
      circle.style.transition = "stroke-dasharray 0.85s ease";
      void circle.getBoundingClientRect();
      circle.style.strokeDasharray = "2 18";
      group.style.opacity = "0";
    }

    function restoreRing(ring) {
      const { circle, group } = ring;
      circle.style.transition = "none";
      circle.style.strokeDasharray = ring.broken;
      circle.style.animation = "";
      void circle.getBoundingClientRect();
      circle.style.transition = "";
      group.style.opacity = "1";
    }

    function spawnSmoke(radius) {
      if (!metrics || typeof smoke.animate !== "function") return;
      const count = 34;
      for (let index = 0; index < count; index += 1) {
        const angle = (index / count) * Math.PI * 2 + Math.random() * 0.24;
        const spread = radius * 0.95 + (Math.random() - 0.5) * 10;
        const dot = document.createElementNS(SVG_NS, "circle");
        dot.setAttribute(
          "cx",
          (metrics.cx + Math.cos(angle) * spread).toFixed(1),
        );
        dot.setAttribute(
          "cy",
          (metrics.cy + Math.sin(angle) * spread).toFixed(1),
        );
        dot.setAttribute("r", `${(2 + Math.random() * 3.4).toFixed(2)}`);
        dot.setAttribute(
          "fill",
          Math.random() < 0.45
            ? "rgba(255, 255, 255, 0.95)"
            : "rgba(41, 107, 64, 0.5)",
        );
        smoke.appendChild(dot);
        const drift = 16 + Math.random() * 42;
        const dx = Math.cos(angle) * drift;
        const dy = Math.sin(angle) * drift - 8 - Math.random() * 22;
        const animation = dot.animate(
          [
            { transform: "translate(0px, 0px) scale(1)", opacity: 0.95 },
            {
              transform: `translate(${dx.toFixed(1)}px, ${dy.toFixed(1)}px) scale(0.2)`,
              opacity: 0,
            },
          ],
          {
            duration: 1600 + Math.random() * 1000,
            // A short travelling wave around the ring reads as a puff of smoke.
            delay: (index / count) * 260 + Math.random() * 160,
            easing: "cubic-bezier(0.22, 0.61, 0.36, 1)",
            fill: "forwards",
          },
        );
        animation.finished.then(() => dot.remove()).catch(() => dot.remove());
      }
    }

    function scheduleCycle(delay) {
      later(() => {
        if (running) cycle();
      }, delay);
    }

    function cycle() {
      if (!running || !metrics) return;
      const outer = rings.reduce((a, b) => (a.slot > b.slot ? a : b));
      const radius = radiusFor(outer.slot);
      spawnSmoke(radius);
      dissolveRing(outer);

      later(() => {
        if (!running) return;
        rings
          .filter((ring) => ring !== outer)
          .sort((a, b) => a.slot - b.slot)
          .forEach((ring, index) => {
            later(() => {
              if (running) setSlot(ring, ring.slot + 1, false);
            }, index * SHIFT_STEP_MS);
          });
        later(() => {
          if (!running) return;
          setSlot(outer, 0, true);
          restoreRing(outer);
        }, SHIFT_MS + (rings.length - 2) * SHIFT_STEP_MS + 80);
      }, DISSOLVE_MS);

      scheduleCycle(CYCLE_MS);
    }

    function start() {
      if (running || reducedMotion) return;
      running = true;
      visionRings.classList.remove("is-idle");
      rings.forEach((ring) => restoreRing(ring));
      resetRings();
      clearTimers();
      scheduleCycle(4200);
    }

    function stop() {
      running = false;
      clearTimers();
      visionRings.classList.add("is-idle");
    }

    build();

    if (!reducedMotion) {
      if ("IntersectionObserver" in window) {
        new IntersectionObserver(
          (entries) => {
            if (entries.some((entry) => entry.isIntersecting)) start();
            else stop();
          },
          { threshold: 0.3 },
        ).observe(visionArt);
      } else {
        start();
      }
    }

    if ("ResizeObserver" in window) {
      let resizeFrame = 0;
      new ResizeObserver(() => {
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = window.requestAnimationFrame(() => {
          const previous = metrics ? `${metrics.width}x${metrics.height}` : "";
          build();
          const next = metrics ? `${metrics.width}x${metrics.height}` : "";
          if (next && next !== previous && running) {
            clearTimers();
            scheduleCycle(CYCLE_MS);
          }
        });
      }).observe(visionArt);
    }
  }

  // Reveal the mobile action dock only after the hero's primary actions have left the screen.
  if (mobileCta && heroActions && "IntersectionObserver" in window) {
    const ctaObserver = new IntersectionObserver(
      (entries) => {
        mobileCta.classList.toggle(
          "visible",
          !entries[0].isIntersecting && window.innerWidth <= 640,
        );
      },
      { threshold: 0.05 },
    );
    ctaObserver.observe(heroActions);
    window.addEventListener("resize", () =>
      mobileCta.classList.toggle(
        "visible",
        window.innerWidth <= 640 && window.scrollY > 420,
      ),
    );
  }

  // Keep the mobile action dock from covering the central Product diagram.
  const foundationArt = $(".foundation-art");
  if (mobileCta && foundationArt && "IntersectionObserver" in window) {
    const foundationDockObserver = new IntersectionObserver(
      (entries) => {
        const diagramVisible = entries.some(
          (entry) => entry.isIntersecting,
        );
        mobileCta.classList.toggle(
          "foundation-overlap",
          diagramVisible && window.innerWidth <= 640,
        );
      },
      { threshold: 0.01 },
    );
    foundationDockObserver.observe(foundationArt);
    window.addEventListener("resize", () => {
      if (window.innerWidth > 640)
        mobileCta.classList.remove("foundation-overlap");
    });
  }

  toTop?.addEventListener("click", () =>
    window.scrollTo({
      top: 0,
      behavior: reducedMotion ? "auto" : "smooth",
    }),
  );
  $("#year").textContent = new Date().getFullYear();
})();
