<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';

$base       = rtrim(APP_URL, '/');
$ogImage    = $base . '/frontend/og-image.png';
$schemaLogo = $base . '/frontend/og-image.svg';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1, viewport-fit=cover"
    />
    <title>
      AltasFarm Livelihood — Free Wellness Community &amp; Growth Opportunity
    </title>
    <meta
      name="description"
      content="AltasFarm Livelihood is a free-to-join community built around wellness products, genuine customer relationships, leadership development and shared growth. No paid entry tiers — ever."
    />
    <meta name="theme-color" content="#0e2a1f" />
    <meta name="author" content="AltasFarm Livelihood" />
    <meta
      name="robots"
      content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1"
    />
    <link rel="canonical" href="<?= e($base) ?>/" />
    <link
      rel="icon"
      type="image/svg+xml"
      href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2032%2032%22%20role%3D%22img%22%20aria-label%3D%22AltasFarm%22%3E%20%3Crect%20width%3D%2232%22%20height%3D%2232%22%20rx%3D%228%22%20fill%3D%22%2310281d%22%2F%3E%20%3Crect%20x%3D%221.1%22%20y%3D%221.1%22%20width%3D%2229.8%22%20height%3D%2229.8%22%20rx%3D%227.2%22%20fill%3D%22none%22%20stroke%3D%22%23c9a35c%22%20stroke-width%3D%220.9%22%2F%3E%20%3Cpath%20d%3D%22M10.4%2023.4%2015.2%209.5%22%20fill%3D%22none%22%20stroke%3D%22%23f7f6ee%22%20stroke-width%3D%221.85%22%20stroke-linecap%3D%22round%22%2F%3E%20%3Cpath%20d%3D%22M21.6%2023.4%2016.8%209.5%22%20fill%3D%22none%22%20stroke%3D%22%23f7f6ee%22%20stroke-width%3D%221.85%22%20stroke-linecap%3D%22round%22%2F%3E%20%3Cg%20transform%3D%22translate%2810.99%2011.99%29%20scale%280.41%29%22%20fill%3D%22none%22%20stroke%3D%22%239fb98e%22%20stroke-width%3D%223.2%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22M11%2020A7%207%200%200%201%209.8%206.1C15.5%205%2017%204.48%2019%202c1%202%202%204.18%202%208%200%205.5-4.78%2010-10%2010Z%22%20fill%3D%22%2310281d%22%2F%3E%3Cpath%20d%3D%22M2%2021c0-3%201.85-5.36%205.08-6C9.5%2014.52%2012%2013%2013%2012%22%2F%3E%3C%2Fg%3E%20%3Ccircle%20cx%3D%2216%22%20cy%3D%227.7%22%20r%3D%222.15%22%20fill%3D%22%23e4c98a%22%2F%3E%20%3Ccircle%20cx%3D%2216%22%20cy%3D%227.7%22%20r%3D%220.85%22%20fill%3D%22%237d5e22%22%2F%3E%20%3Cpath%20d%3D%22M9%2023.9h3.6%22%20fill%3D%22none%22%20stroke%3D%22%23f7f6ee%22%20stroke-width%3D%221.45%22%20stroke-linecap%3D%22round%22%2F%3E%20%3Cpath%20d%3D%22M19.4%2023.9h3.6%22%20fill%3D%22none%22%20stroke%3D%22%23f7f6ee%22%20stroke-width%3D%221.45%22%20stroke-linecap%3D%22round%22%2F%3E%20%3C%2Fsvg%3E"
    />

    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="AltasFarm Livelihood" />
    <meta
      property="og:title"
      content="AltasFarm Livelihood — Free Wellness Community &amp; Growth Opportunity"
    />
    <meta
      property="og:description"
      content="AltasFarm Livelihood is a free-to-join community built around wellness products, genuine customer relationships, leadership development and shared growth. No paid entry tiers — ever."
    />
    <meta property="og:url" content="<?= e($base) ?>/" />
    <meta property="og:image" content="<?= e($ogImage) ?>" />
    <meta property="og:image:type" content="image/png" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta
      name="twitter:title"
      content="AltasFarm Livelihood — Free Wellness Community &amp; Growth Opportunity"
    />
    <meta
      name="twitter:description"
      content="AltasFarm Livelihood is a free-to-join community built around wellness products, genuine customer relationships, leadership development and shared growth. No paid entry tiers — ever."
    />
    <meta name="twitter:image" content="<?= e($ogImage) ?>" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,300..600&amp;family=Instrument+Sans:ital,wght@0,400..700;1,400..700&amp;display=swap"
    />

    <script>
      document.documentElement.classList.add("js");
    </script>

    <script type="application/ld+json">
      {
        "@context": "https://schema.org",
        "@graph": [
          {
            "@type": "Organization",
            "@id": "<?= $base ?>/#organization",
            "name": "AltasFarm Livelihood",
            "url": "<?= $base ?>/",
            "logo": "<?= $schemaLogo ?>",
            "slogan": "Grow something good. For life.",
            "description": "A free-to-join community built around wellness products, repeat customer relationships, leadership development and shared growth.",
            "knowsAbout": [
              "Wellness products",
              "Community building",
              "Leadership development",
              "Customer relationships"
            ],
            "address": {
              "@type": "PostalAddress",
              "streetAddress": "Rang-ay",
              "addressLocality": "Cabatuan",
              "addressRegion": "Isabela",
              "postalCode": "3315",
              "addressCountry": "PH"
            },
            "geo": {
              "@type": "GeoCoordinates",
              "latitude": 16.9266802,
              "longitude": 121.616111
            },
            "hasMap": "https://maps.app.goo.gl/3xmJeKfKTj1NyaJ29",
            "contactPoint": {
              "@type": "ContactPoint",
              "contactType": "customer support",
              "email": "contact@altasfarm.life",
              "telephone": ["+639060987081", "+639996689052"],
              "availableLanguage": ["en", "fil"]
            },
            "sameAs": [
              "https://www.facebook.com/people/Altas-Farm/61595099030024/"
            ],
            "areaServed": {
              "@type": "Country",
              "name": "Philippines"
            }
          },
          {
            "@type": "WebSite",
            "@id": "<?= $base ?>/#website",
            "url": "<?= $base ?>/",
            "name": "AltasFarm Livelihood",
            "publisher": {
              "@id": "<?= $base ?>/#organization"
            },
            "inLanguage": "en"
          },
          {
            "@type": "WebPage",
            "@id": "<?= $base ?>/#webpage",
            "url": "<?= $base ?>/",
            "name": "AltasFarm Livelihood — Free Wellness Community & Growth Opportunity",
            "isPartOf": {
              "@id": "<?= $base ?>/#website"
            },
            "about": {
              "@id": "<?= $base ?>/#organization"
            },
            "primaryImageOfPage": "<?= $ogImage ?>",
            "inLanguage": "en",
            "speakable": {
              "@type": "SpeakableSpecification",
              "cssSelector": ["#hero-title", ".hero-copy"]
            }
          },
          {
            "@type": "FAQPage",
            "@id": "<?= $base ?>/#faq",
            "mainEntity": [
              {
                "@type": "Question",
                "name": "What is AltasFarm Livelihood?",
                "acceptedAnswer": {
                  "@type": "Answer",
                  "text": "AltasFarm Livelihood is a community built around wellness and everyday products. Members serve repeat customers, support one another, and may advance through leadership ranks as they qualify."
                }
              },
              {
                "@type": "Question",
                "name": "Is registration really free?",
                "acceptedAnswer": {
                  "@type": "Answer",
                  "text": "Yes. Registering costs nothing, and there are no paid membership packages or entry tiers. Any product purchase is optional and separate from registration."
                }
              },
              {
                "@type": "Question",
                "name": "What products will be available?",
                "acceptedAnswer": {
                  "@type": "Answer",
                  "text": "The range may grow over time and may include wellness, herbal, organic and other everyday products. Current descriptions, prices and availability come from our team or the product catalog."
                }
              },
              {
                "@type": "Question",
                "name": "How do members take part in the livelihood opportunity?",
                "acceptedAnswer": {
                  "@type": "Answer",
                  "text": "Participation centers on qualifying repeat-purchase activity and building a team responsibly. The official program guide sets out the requirements, qualification rules and any compensation, so you can read them before you decide."
                }
              },
              {
                "@type": "Question",
                "name": "What does profit sharing mean?",
                "acceptedAnswer": {
                  "@type": "Answer",
                  "text": "Qualified ranks may be eligible to share in a designated portion of the business’s actual net profit. Eligibility and amounts follow the published policy, and no distribution is guaranteed."
                }
              },
              {
                "@type": "Question",
                "name": "Do I need sales or leadership experience?",
                "acceptedAnswer": {
                  "@type": "Answer",
                  "text": "No. You can inquire or register with no experience. Start by learning about the products and asking a team leader to explain the program and your options."
                }
              },
              {
                "@type": "Question",
                "name": "Will I earn just by inviting people to register?",
                "acceptedAnswer": {
                  "@type": "Answer",
                  "text": "Not on its own. Registering people is not an automatic entitlement to compensation — the program is built on real product activity, repeat customers and responsible team development."
                }
              }
            ]
          }
        ]
      }
    </script>

    <style>
      /*! tailwindcss v4.3.3 | MIT License | https://tailwindcss.com */
      @layer properties {
        @supports (((-webkit-hyphens: none)) and (not (margin-trim: inline))) or
          ((-moz-orient: inline) and (not (color: rgb(from red r g b)))) {
          *,
          :before,
          :after,
          ::backdrop {
            --tw-rotate-x: initial;
            --tw-rotate-y: initial;
            --tw-rotate-z: initial;
            --tw-skew-x: initial;
            --tw-skew-y: initial;
            --tw-border-style: solid;
            --tw-font-weight: initial;
            --tw-shadow: 0 0 #0000;
            --tw-shadow-color: initial;
            --tw-shadow-alpha: 100%;
            --tw-inset-shadow: 0 0 #0000;
            --tw-inset-shadow-color: initial;
            --tw-inset-shadow-alpha: 100%;
            --tw-ring-color: initial;
            --tw-ring-shadow: 0 0 #0000;
            --tw-inset-ring-color: initial;
            --tw-inset-ring-shadow: 0 0 #0000;
            --tw-ring-inset: initial;
            --tw-ring-offset-width: 0px;
            --tw-ring-offset-color: #fff;
            --tw-ring-offset-shadow: 0 0 #0000;
          }
        }
      }

      @layer theme {
        :root,
        :host {
          --font-sans: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
          --font-mono:
            ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas,
            "Liberation Mono", "Courier New", monospace;
          --color-red-500: oklch(63.7% 0.237 25.331);
          --color-zinc-50: oklch(98.5% 0 none);
          --color-zinc-400: oklch(70.5% 0.015 286.067);
          --color-zinc-500: oklch(55.2% 0.016 285.938);
          --color-zinc-900: oklch(21% 0.006 285.885);
          --color-zinc-950: oklch(14.1% 0.005 285.823);
          --color-neutral-100: oklch(97% 0 none);
          --color-neutral-300: oklch(87% 0 none);
          --color-neutral-700: oklch(37.1% 0 none);
          --color-neutral-900: oklch(20.5% 0 none);
          --color-black: #000;
          --color-white: #fff;
          --spacing: 0.25rem;
          --container-sm: 24rem;
          --container-md: 28rem;
          --text-sm: 0.875rem;
          --text-sm--line-height: calc(1.25 / 0.875);
          --text-lg: 1.125rem;
          --text-lg--line-height: calc(1.75 / 1.125);
          --font-weight-medium: 500;
          --font-weight-semibold: 600;
          --radius-md: 0.375rem;
          --radius-lg: 0.5rem;
          --shadow-sm: 0 1px 3px 0 #0000001a, 0 1px 2px -1px #0000001a;
          --shadow-lg: 0 10px 15px -3px #0000001a, 0 4px 6px -4px #0000001a;
          --default-font-family: var(--font-sans);
          --default-mono-font-family: var(--font-mono);
          --font-display: "Fraunces", Georgia, "Times New Roman", serif;
        }
      }

      @layer base {
        *,
        :after,
        :before,
        ::backdrop {
          box-sizing: border-box;
          border: 0 solid;
          margin: 0;
          padding: 0;
        }

        ::file-selector-button {
          box-sizing: border-box;
          border: 0 solid;
          margin: 0;
          padding: 0;
        }

        html,
        :host {
          -webkit-text-size-adjust: 100%;
          tab-size: 4;
          line-height: 1.5;
          font-family: var(
            --default-font-family,
            -apple-system,
            BlinkMacSystemFont,
            "Segoe UI",
            Roboto,
            "Helvetica Neue",
            "Noto Sans",
            Arial,
            sans-serif,
            "Apple Color Emoji",
            "Segoe UI Emoji",
            "Segoe UI Symbol",
            "Noto Color Emoji"
          );
          font-feature-settings: var(--default-font-feature-settings, normal);
          font-variation-settings: var(
            --default-font-variation-settings,
            normal
          );
          -webkit-tap-highlight-color: transparent;
        }

        hr {
          height: 0;
          color: inherit;
          border-top-width: 1px;
        }

        abbr:where([title]) {
          -webkit-text-decoration: underline dotted;
          text-decoration: underline dotted;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
          font-size: inherit;
          font-weight: inherit;
        }

        a {
          color: inherit;
          -webkit-text-decoration: inherit;
          -webkit-text-decoration: inherit;
          text-decoration: inherit;
        }

        b,
        strong {
          font-weight: bolder;
        }

        code,
        kbd,
        samp,
        pre {
          font-family: var(
            --default-mono-font-family,
            ui-monospace,
            SFMono-Regular,
            Menlo,
            Monaco,
            Consolas,
            "Liberation Mono",
            "Courier New",
            monospace
          );
          font-feature-settings: var(
            --default-mono-font-feature-settings,
            normal
          );
          font-variation-settings: var(
            --default-mono-font-variation-settings,
            normal
          );
          font-size: 1em;
        }

        small {
          font-size: 80%;
        }

        sub,
        sup {
          vertical-align: baseline;
          font-size: 75%;
          line-height: 0;
          position: relative;
        }

        sub {
          bottom: -0.25em;
        }

        sup {
          top: -0.5em;
        }

        table {
          text-indent: 0;
          border-color: inherit;
          border-collapse: collapse;
        }

        :-moz-focusring:where(:not(iframe)) {
          outline: auto;
        }

        progress {
          vertical-align: baseline;
        }

        summary {
          display: list-item;
        }

        ol,
        ul,
        menu {
          list-style: none;
        }

        img,
        svg,
        video,
        canvas,
        audio,
        iframe,
        embed,
        object {
          vertical-align: middle;
          display: block;
        }

        img,
        video {
          max-width: 100%;
          height: auto;
        }

        button,
        input,
        select,
        optgroup,
        textarea {
          font: inherit;
          font-feature-settings: inherit;
          font-variation-settings: inherit;
          letter-spacing: inherit;
          color: inherit;
          opacity: 1;
          background-color: #0000;
          border-radius: 0;
        }

        ::file-selector-button {
          font: inherit;
          font-feature-settings: inherit;
          font-variation-settings: inherit;
          letter-spacing: inherit;
          color: inherit;
          opacity: 1;
          background-color: #0000;
          border-radius: 0;
        }

        :where(select:is([multiple], [size])) optgroup {
          font-weight: bolder;
        }

        :where(select:is([multiple], [size])) optgroup option {
          padding-inline-start: 20px;
        }

        ::file-selector-button {
          margin-inline-end: 4px;
        }

        ::placeholder {
          opacity: 1;
        }

        @supports (not ((-webkit-appearance: -apple-pay-button))) or
          (contain-intrinsic-size: 1px) {
          ::placeholder {
            color: currentColor;
          }

          @supports (color: color-mix(in lab, red, red)) {
            ::placeholder {
              color: color-mix(in oklab, currentcolor 50%, transparent);
            }
          }
        }

        textarea {
          resize: vertical;
        }

        ::-webkit-search-decoration {
          -webkit-appearance: none;
        }

        ::-webkit-date-and-time-value {
          min-height: 1lh;
          text-align: inherit;
        }

        ::-webkit-datetime-edit {
          display: inline-flex;
        }

        ::-webkit-datetime-edit-fields-wrapper {
          padding: 0;
        }

        ::-webkit-datetime-edit {
          padding-block: 0;
        }

        ::-webkit-datetime-edit-year-field {
          padding-block: 0;
        }

        ::-webkit-datetime-edit-month-field {
          padding-block: 0;
        }

        ::-webkit-datetime-edit-day-field {
          padding-block: 0;
        }

        ::-webkit-datetime-edit-hour-field {
          padding-block: 0;
        }

        ::-webkit-datetime-edit-minute-field {
          padding-block: 0;
        }

        ::-webkit-datetime-edit-second-field {
          padding-block: 0;
        }

        ::-webkit-datetime-edit-millisecond-field {
          padding-block: 0;
        }

        ::-webkit-datetime-edit-meridiem-field {
          padding-block: 0;
        }

        ::-webkit-calendar-picker-indicator {
          line-height: 1;
        }

        :-moz-ui-invalid {
          box-shadow: none;
        }

        button,
        input:where([type="button"], [type="reset"], [type="submit"]) {
          appearance: button;
        }

        ::file-selector-button {
          appearance: button;
        }

        ::-webkit-inner-spin-button {
          height: auto;
        }

        ::-webkit-outer-spin-button {
          height: auto;
        }

        [hidden]:where(:not([hidden="until-found"])) {
          display: none !important;
        }

        button:not(:disabled),
        [role="button"]:not(:disabled) {
          cursor: pointer;
        }
      }

      @layer components;

      @layer utilities {
        .visible {
          visibility: visible;
        }

        .absolute {
          position: absolute;
        }

        .fixed {
          position: fixed;
        }

        .relative {
          position: relative;
        }

        .static {
          position: static;
        }

        .container {
          width: 100%;
        }

        @media (width >= 40rem) {
          .container {
            max-width: 40rem;
          }
        }

        @media (width >= 48rem) {
          .container {
            max-width: 48rem;
          }
        }

        @media (width >= 64rem) {
          .container {
            max-width: 64rem;
          }
        }

        @media (width >= 80rem) {
          .container {
            max-width: 80rem;
          }
        }

        @media (width >= 96rem) {
          .container {
            max-width: 96rem;
          }
        }

        .block {
          display: block;
        }

        .flex {
          display: flex;
        }

        .grid {
          display: grid;
        }

        .hidden {
          display: none;
        }

        .inline {
          display: inline;
        }

        .table {
          display: table;
        }

        .size-10 {
          width: calc(var(--spacing) * 10);
          height: calc(var(--spacing) * 10);
        }

        .h-8 {
          height: calc(var(--spacing) * 8);
        }

        .min-h-screen {
          min-height: 100vh;
        }

        .w-8 {
          width: calc(var(--spacing) * 8);
        }

        .w-full {
          width: 100%;
        }

        .max-w-md {
          max-width: var(--container-md);
        }

        .max-w-sm {
          max-width: var(--container-sm);
        }

        .grow {
          flex-grow: 1;
        }

        .transform {
          transform: var(--tw-rotate-x,) var(--tw-rotate-y,) var(--tw-rotate-z,)
            var(--tw-skew-x,) var(--tw-skew-y,);
        }

        .cursor-pointer {
          cursor: pointer;
        }

        .resize {
          resize: both;
        }

        .flex-col {
          flex-direction: column;
        }

        .place-items-center {
          place-items: center;
        }

        .items-center {
          align-items: center;
        }

        .justify-center {
          justify-content: center;
        }

        .gap-2 {
          gap: calc(var(--spacing) * 2);
        }

        .gap-3 {
          gap: calc(var(--spacing) * 3);
        }

        .rounded-full {
          border-radius: 3.40282e38px;
        }

        .rounded-md {
          border-radius: var(--radius-md);
        }

        .border {
          border-style: var(--tw-border-style);
          border-width: 1px;
        }

        .border-neutral-300 {
          border-color: var(--color-neutral-300);
        }

        .bg-black\/10 {
          background-color: #0000001a;
        }

        @supports (color: color-mix(in lab, red, red)) {
          .bg-black\/10 {
            background-color: color-mix(
              in oklab,
              var(--color-black) 10%,
              transparent
            );
          }
        }

        .bg-zinc-50 {
          background-color: var(--color-zinc-50);
        }

        .object-cover {
          object-fit: cover;
        }

        .px-4 {
          padding-inline: calc(var(--spacing) * 4);
        }

        .px-6 {
          padding-inline: calc(var(--spacing) * 6);
        }

        .py-2 {
          padding-block: calc(var(--spacing) * 2);
        }

        .text-center {
          text-align: center;
        }

        .text-lg {
          font-size: var(--text-lg);
          line-height: var(--tw-leading, var(--text-lg--line-height));
        }

        .text-sm {
          font-size: var(--text-sm);
          line-height: var(--tw-leading, var(--text-sm--line-height));
        }

        .font-medium {
          --tw-font-weight: var(--font-weight-medium);
          font-weight: var(--font-weight-medium);
        }

        .font-semibold {
          --tw-font-weight: var(--font-weight-semibold);
          font-weight: var(--font-weight-semibold);
        }

        .break-words {
          overflow-wrap: break-word;
        }

        .text-red-500 {
          color: var(--color-red-500);
        }

        .text-zinc-500 {
          color: var(--color-zinc-500);
        }

        .text-zinc-900 {
          color: var(--color-zinc-900);
        }

        .underline-offset-4 {
          text-underline-offset: 4px;
        }

        .opacity-70 {
          opacity: 0.7;
        }

        .ring {
          --tw-ring-shadow: var(--tw-ring-inset,) 0 0 0
            calc(1px + var(--tw-ring-offset-width))
            var(--tw-ring-color, currentcolor);
          box-shadow:
            var(--tw-inset-shadow), var(--tw-inset-ring-shadow),
            var(--tw-ring-offset-shadow), var(--tw-ring-shadow),
            var(--tw-shadow);
        }

        @media (hover: hover) {
          .hover\:bg-neutral-100:hover {
            background-color: var(--color-neutral-100);
          }

          .hover\:underline:hover {
            text-decoration-line: underline;
          }
        }

        .disabled\:cursor-wait:disabled {
          cursor: wait;
        }

        .disabled\:no-underline:disabled {
          text-decoration-line: none;
        }

        @media (prefers-color-scheme: dark) {
          .dark\:border-neutral-700 {
            border-color: var(--color-neutral-700);
          }

          .dark\:bg-white\/20 {
            background-color: #fff3;
          }

          @supports (color: color-mix(in lab, red, red)) {
            .dark\:bg-white\/20 {
              background-color: color-mix(
                in oklab,
                var(--color-white) 20%,
                transparent
              );
            }
          }

          .dark\:bg-zinc-950 {
            background-color: var(--color-zinc-950);
          }

          .dark\:text-zinc-50 {
            color: var(--color-zinc-50);
          }

          .dark\:text-zinc-400 {
            color: var(--color-zinc-400);
          }

          @media (hover: hover) {
            .dark\:hover\:bg-neutral-900:hover {
              background-color: var(--color-neutral-900);
            }
          }
        }
      }

      :root {
        --forest-950: #081510;
        --forest-900: #0e2a1f;
        --forest-800: #12362a;
        --forest-700: #1b4736;
        --forest-600: #2c5a44;
        --leaf: #3f6b4d;
        --sage: #a9b99a;
        --sage-200: #e7ecdc;
        --cream: #f5f3e9;
        --paper: #fdfbf4;
        --ink: #1a2b20;
        --muted: #5c6b5f;
        --gold: #c9a35c;
        --gold-deep: #a8842f;
        --gold-light: #e7cf9b;
        --line: #e2e6d6;
        --radius: 24px;
        --radius-lg: 32px;
        --shadow-sm: 0 2px 10px #12362a0f;
        --shadow: 0 26px 70px -24px #12362a4d;
        --shadow-lg: 0 46px 110px -36px #12362a6b;
        --ease: cubic-bezier(0.22, 1, 0.36, 1);
        --font-display: "Fraunces", Georgia, "Times New Roman", serif;
        --font-sans:
          "Instrument Sans", ui-sans-serif, system-ui, -apple-system,
          "Segoe UI", sans-serif;
      }

      *,
      :before,
      :after {
        box-sizing: border-box;
      }

      html {
        scroll-behavior: smooth;
        -webkit-text-size-adjust: 100%;
        scroll-padding-top: 118px;
      }

      html.nav-locked,
      html.nav-locked body {
        overflow: hidden;
      }

      body {
        background: var(--cream);
        color: var(--ink);
        font-family: var(--font-sans);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        text-rendering: optimizelegibility;
        margin: 0;
        font-size: 16px;
        line-height: 1.65;
        overflow-x: hidden;
      }

      body:after {
        content: "";
        z-index: 9999;
        pointer-events: none;
        opacity: 0.028;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='180' height='180' filter='url(%23n)'/%3E%3C/svg%3E");
        position: fixed;
        inset: 0;
      }

      a {
        color: inherit;
        text-decoration: none;
      }

      button,
      input,
      select,
      textarea {
        font: inherit;
        color: inherit;
      }

      button,
      a {
        -webkit-tap-highlight-color: transparent;
      }

      img,
      svg {
        max-width: 100%;
        display: block;
      }

      h1,
      h2,
      h3,
      h4,
      p {
        margin-top: 0;
      }

      ::selection {
        background: var(--gold-light);
        color: var(--forest-900);
      }

      :focus-visible {
        outline: 2px solid var(--gold);
        outline-offset: 3px;
        border-radius: 6px;
      }

      .skip-link {
        z-index: 400;
        background: var(--paper);
        color: var(--forest-800);
        box-shadow: var(--shadow-sm);
        border-radius: 10px;
        padding: 12px 18px;
        font-size: 13px;
        font-weight: 700;
        position: absolute;
        top: 10px;
        left: -9999px;
      }

      .skip-link:focus {
        left: 10px;
      }

      .container {
        width: min(1200px, 100% - 56px);
        margin-inline: auto;
      }

      main {
        overflow: hidden;
      }

      h1,
      h2,
      h3,
      .serif {
        font-family: var(--font-display);
        font-optical-sizing: auto;
        font-weight: 500;
      }

      .eyebrow {
        color: var(--forest-600);
        letter-spacing: 0.2em;
        text-transform: uppercase;
        align-items: center;
        gap: 10px;
        font-size: 11px;
        font-weight: 700;
        display: inline-flex;
      }

      .eyebrow:before {
        content: "";
        background: var(--gold);
        border-radius: 2px;
        width: 24px;
        height: 2px;
      }

      .eyebrow.on-dark {
        color: var(--gold-light);
      }

      .section-head {
        max-width: 680px;
        margin-bottom: 52px;
      }

      .section-head h2 {
        letter-spacing: -0.045em;
        color: var(--forest-800);
        margin: 16px 0;
        font-size: clamp(34px, 4.1vw, 52px);
        line-height: 1.06;
      }

      .section-head p {
        max-width: 600px;
        color: var(--muted);
        margin: 0;
        font-size: 14.5px;
        line-height: 1.85;
      }

      .button {
        letter-spacing: 0.01em;
        cursor: pointer;
        white-space: nowrap;
        transition:
          transform 0.3s var(--ease),
          background 0.3s var(--ease),
          box-shadow 0.3s var(--ease),
          color 0.3s var(--ease),
          border-color 0.3s var(--ease);
        border: 1px solid #0000;
        border-radius: 999px;
        justify-content: center;
        align-items: center;
        gap: 9px;
        padding: 14px 24px;
        font-size: 12.5px;
        font-weight: 700;
        display: inline-flex;
        position: relative;
        overflow: hidden;
      }

      .button:hover {
        transform: translateY(-2px);
      }

      .button svg {
        width: 14px;
        height: 14px;
      }

      .button-primary {
        background: var(--forest-800);
        color: #fdfbf4;
        box-shadow: 0 10px 24px -10px #12362a8c;
      }

      .button-primary:hover {
        background: var(--forest-950);
        box-shadow: 0 16px 34px -12px #12362a99;
      }

      .button-gold {
        background: var(--gold);
        color: var(--forest-900);
      }

      .button-gold:hover {
        background: var(--gold-light);
      }

      .button-ghost {
        color: var(--forest-800);
        background: none;
        border-color: #c9d3c0;
      }

      .button-ghost:hover {
        background: #eef1e4;
        border-color: #b6c4ab;
      }

      .text-link {
        cursor: pointer;
        color: var(--forest-800);
        transition:
          gap 0.3s var(--ease),
          border-color 0.3s var(--ease);
        background: none;
        border: 0;
        border-bottom: 1px solid #9bab91;
        align-items: center;
        gap: 9px;
        margin-top: 14px;
        padding-bottom: 5px;
        font-size: 12.5px;
        font-weight: 700;
        display: inline-flex;
      }

      .text-link:hover {
        border-color: var(--forest-800);
        gap: 15px;
      }

      .text-link svg {
        width: 14px;
        height: 14px;
      }

      .scroll-progress {
        z-index: 220;
        pointer-events: none;
        height: 2px;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
      }

      .scroll-progress span {
        transform-origin: 0;
        background: var(--gold);
        will-change: transform;
        height: 100%;
        display: block;
        transform: scaleX(0);
      }

      .utility-bar {
        background: var(--forest-950);
        color: #ffffffb3;
        letter-spacing: 0.03em;
        border-bottom: 1px solid #ffffff0f;
        font-size: 11px;
      }

      .utility-inner {
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        width: min(1200px, 100% - 56px);
        min-height: 36px;
        margin: 0 auto;
        display: flex;
      }

      .utility-left,
      .utility-right {
        flex-wrap: wrap;
        align-items: center;
        gap: 14px;
        display: flex;
      }

      .utility-left span,
      .utility-right span {
        white-space: nowrap;
      }

      .utility-bar strong {
        color: #ffffffeb;
        font-weight: 600;
      }

      .utility-bar .dot {
        color: #e7cf9b8c;
      }

      .site-header {
        z-index: 200;
        -webkit-backdrop-filter: blur(18px) saturate(140%);
        transition:
          box-shadow 0.35s var(--ease),
          background 0.35s var(--ease);
        background: #f5f3e9d6;
        border-bottom: 1px solid #e2e6d6b3;
        position: sticky;
        top: 0;
      }

      .site-header.is-scrolled {
        background: #f5f3e9f5;
        box-shadow: 0 12px 32px -20px #12362a66;
      }

      .nav-wrap {
        max-width: 1200px;
        transition: padding 0.35s var(--ease);
        align-items: center;
        gap: 30px;
        margin: auto;
        padding: 14px 28px;
        display: flex;
      }

      .site-header.is-scrolled .nav-wrap {
        padding-top: 9px;
        padding-bottom: 9px;
      }

      .brand {
        flex-shrink: 0;
        align-items: center;
        gap: 12px;
        display: flex;
      }

      .brand-mark {
        width: 46px;
        height: 46px;
        transition:
          transform 0.4s var(--ease),
          box-shadow 0.4s var(--ease);
        border-radius: 14px;
        flex: 0 0 46px;
        box-shadow: 0 6px 18px -8px #12362a8c;
      }

      .brand:hover .brand-mark {
        transform: translateY(-2px) rotate(-3deg);
        box-shadow: 0 12px 26px -10px #12362a99;
      }

      .brand-name {
        font-family: var(--font-display);
        letter-spacing: -0.04em;
        color: var(--forest-800);
        font-size: 25px;
        font-weight: 600;
        line-height: 1;
        display: block;
      }

      .brand-sub {
        color: #6b7a68;
        letter-spacing: 0.28em;
        text-transform: uppercase;
        margin-top: 5px;
        font-size: 8.5px;
        font-weight: 700;
        line-height: 1;
        display: block;
      }

      .nav-links {
        color: #4d5d50;
        align-items: center;
        gap: clamp(14px, 2.2vw, 30px);
        margin-left: auto;
        font-size: 12.5px;
        font-weight: 600;
        display: flex;
      }

      .nav-links a:not(.button) {
        transition: color 0.25s var(--ease);
        padding: 7px 0;
        position: relative;
      }

      .nav-links a:not(.button):after {
        content: "";
        background: var(--gold);
        transform-origin: 0;
        height: 1.5px;
        transition: transform 0.4s var(--ease);
        border-radius: 2px;
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        transform: scaleX(0);
      }

      .nav-links a:not(.button):hover,
      .nav-links a:not(.button).is-active {
        color: var(--forest-800);
      }

      .nav-links a:not(.button):hover:after,
      .nav-links a:not(.button).is-active:after {
        transform: scaleX(1);
      }

      .menu-toggle {
        border: 1px solid var(--line);
        background: var(--paper);
        color: var(--forest-800);
        cursor: pointer;
        width: 46px;
        height: 46px;
        transition: background 0.25s var(--ease);
        border-radius: 13px;
        justify-content: center;
        align-items: center;
        display: none;
      }

      .menu-toggle:hover {
        background: #eef1e4;
      }

      .mobile-nav {
        z-index: 250;
        background: linear-gradient(
          170deg,
          var(--forest-900),
          var(--forest-950)
        );
        visibility: hidden;
        transition:
          transform 0.55s var(--ease),
          visibility 0s linear 0.55s;
        flex-direction: column;
        display: flex;
        position: fixed;
        inset: 0;
        transform: translateX(102%);
      }

      .mobile-nav.open {
        visibility: visible;
        transition: transform 0.55s var(--ease);
        transform: none;
      }

      @media (width >= 761px) {
        .mobile-nav {
          display: none;
        }
      }

      .mobile-nav-head {
        border-bottom: 1px solid #ffffff14;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        padding: 13px 20px;
        display: flex;
      }

      .mobile-nav-head .brand-name {
        color: #fdfbf4;
      }

      .mobile-nav-head .brand-sub {
        color: #e7cf9bbf;
      }

      .mobile-nav-close {
        color: #fff;
        cursor: pointer;
        width: 46px;
        height: 46px;
        transition: background 0.25s var(--ease);
        background: #ffffff0f;
        border: 1px solid #ffffff38;
        border-radius: 13px;
        place-items: center;
        display: grid;
      }

      .mobile-nav-close:hover {
        background: #ffffff24;
      }

      .mobile-nav-body {
        flex-direction: column;
        flex: 1;
        padding: 14px 24px 26px;
        display: flex;
        overflow-y: auto;
      }

      .mobile-nav-links {
        flex-direction: column;
        display: flex;
      }

      .mobile-nav-links a {
        font-family: var(--font-display);
        letter-spacing: -0.02em;
        color: #f2f4e8;
        opacity: 0;
        transition:
          opacity 0.5s var(--ease),
          transform 0.5s var(--ease),
          color 0.25s var(--ease);
        border-bottom: 1px solid #ffffff14;
        align-items: baseline;
        gap: 16px;
        padding: 17px 2px;
        font-size: 27px;
        font-weight: 500;
        display: flex;
        transform: translateY(16px);
      }

      .mobile-nav-links a:active {
        color: var(--gold-light);
      }

      .mobile-nav-links a small {
        font-family: var(--font-sans);
        letter-spacing: 0.2em;
        color: var(--gold-light);
        font-size: 10px;
        font-weight: 700;
      }

      .mobile-nav.open .mobile-nav-links a {
        opacity: 1;
        transform: none;
      }

      .mobile-nav.open .mobile-nav-links a:first-child {
        transition-delay: 0.1s;
      }

      .mobile-nav.open .mobile-nav-links a:nth-child(2) {
        transition-delay: 0.16s;
      }

      .mobile-nav.open .mobile-nav-links a:nth-child(3) {
        transition-delay: 0.22s;
      }

      .mobile-nav.open .mobile-nav-links a:nth-child(4) {
        transition-delay: 0.28s;
      }

      .mobile-nav.open .mobile-nav-links a:nth-child(5) {
        transition-delay: 0.34s;
      }

      .mobile-nav-foot {
        gap: 14px;
        margin-top: auto;
        padding-top: 26px;
        display: grid;
      }

      .mobile-nav-foot .button {
        justify-content: center;
        padding: 16px 24px;
        font-size: 13px;
      }

      .mobile-nav-mail,
      .mobile-nav-login {
        text-align: center;
        color: var(--gold-light);
        padding: 4px 0;
        font-size: 13px;
        font-weight: 700;
        display: block;
      }

      .mobile-nav-note {
        color: #ffffff80;
        text-align: center;
        margin: 0;
        font-size: 11px;
        line-height: 1.75;
      }

      .mobile-nav-note a {
        color: var(--gold-light);
        border-bottom: 1px solid #e7cf9b66;
        font-weight: 700;
      }

      .hero {
        isolation: isolate;
        padding: 72px 0 92px;
        position: relative;
      }

      .hero:before {
        content: "";
        z-index: -1;
        pointer-events: none;
        background: radial-gradient(circle, #e7cf9b57, #e7cf9b00 66%);
        border-radius: 50%;
        width: 640px;
        height: 640px;
        position: absolute;
        top: -320px;
        left: -270px;
      }

      .hero:after {
        content: "";
        z-index: -1;
        pointer-events: none;
        background: radial-gradient(circle, #a9b99a5c, #a9b99a00 68%);
        border-radius: 50%;
        width: 560px;
        height: 560px;
        position: absolute;
        bottom: -300px;
        right: -250px;
      }

      .hero-grid {
        grid-template-columns: minmax(0, 1.04fr) minmax(390px, 0.96fr);
        align-items: center;
        gap: clamp(34px, 6vw, 84px);
        display: grid;
      }

      h1 {
        letter-spacing: -0.05em;
        color: var(--forest-800);
        margin: 20px 0 6px;
        font-size: clamp(44px, 5.5vw, 74px);
        line-height: 1.02;
      }

      h1 em {
        color: #4c7257;
        font-style: italic;
        font-weight: 400;
      }

      .line {
        margin-bottom: -0.09em;
        padding-bottom: 0.09em;
        display: block;
        overflow: hidden;
      }

      .line-inner {
        animation: lineUp 0.95s var(--ease) forwards;
        display: inline-block;
        transform: translateY(112%);
      }

      .line:nth-child(2) .line-inner {
        animation-delay: 0.14s;
      }

      @keyframes lineUp {
        to {
          transform: translateY(0);
        }
      }

      .flourish {
        width: 212px;
        height: 16px;
        margin: 4px 0 18px;
        overflow: visible;
      }

      .flourish path {
        stroke: var(--gold);
        stroke-width: 2.4px;
        fill: none;
        stroke-linecap: round;
        stroke-dasharray: 240;
        stroke-dashoffset: 240px;
        animation: draw 1.1s var(--ease) 0.85s forwards;
      }

      .flourish .f2 {
        stroke-width: 1.6px;
        opacity: 0.5;
        animation-delay: 1.05s;
      }

      @keyframes draw {
        to {
          stroke-dashoffset: 0;
        }
      }

      .hero-copy {
        color: #5c6b5e;
        max-width: 530px;
        font-size: 15px;
        line-height: 1.85;
      }

      .hero-actions {
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 28px;
        display: flex;
      }

      .hero-note {
        color: #718073;
        align-items: center;
        gap: 10px;
        margin-top: 22px;
        font-size: 11.5px;
        display: flex;
      }

      .check-circle {
        background: var(--sage-200);
        width: 20px;
        height: 20px;
        color: var(--forest-600);
        border-radius: 50%;
        flex: 0 0 20px;
        place-items: center;
        display: inline-grid;
      }

      .check-circle svg {
        width: 11px;
        height: 11px;
      }

      .hero-stats {
        border-top: 1px solid var(--line);
        grid-template-columns: repeat(3, auto);
        justify-content: start;
        gap: 34px;
        max-width: 520px;
        margin-top: 32px;
        padding-top: 22px;
        display: grid;
      }

      .hero-stat strong {
        font-family: var(--font-display);
        letter-spacing: -0.02em;
        color: var(--forest-800);
        font-size: 26px;
        font-weight: 500;
        display: block;
      }

      .hero-stat span {
        color: #7c8779;
        max-width: 16ch;
        margin-top: 4px;
        font-size: 10.5px;
        line-height: 1.5;
        display: block;
      }

      .hero-visual-wrap {
        animation: rise 1.1s var(--ease) 0.12s both;
      }

      .hero-visual {
        aspect-ratio: 600 / 660;
        border-radius: var(--radius-lg);
        min-height: 470px;
        box-shadow: var(--shadow-lg);
        isolation: isolate;
        will-change: transform;
        background: linear-gradient(165deg, #f2f4e6, #e0e8d1);
        position: relative;
        overflow: hidden;
      }

      .hero-visual:before {
        content: "";
        z-index: 4;
        pointer-events: none;
        border: 1px solid #12362a29;
        border-radius: 24px;
        position: absolute;
        inset: 14px;
      }

      .hero-visual .scene {
        width: 100%;
        height: 100%;
        position: absolute;
        inset: 0;
      }

      #seeds-canvas {
        z-index: 2;
        pointer-events: none;
        width: 100%;
        height: 100%;
        position: absolute;
        inset: 0;
      }

      .art-kicker {
        z-index: 6;
        color: #3d5a45;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        opacity: 0.85;
        font-size: 9px;
        font-weight: 700;
        position: absolute;
        bottom: 26px;
        left: 32px;
      }

      .float-card {
        z-index: 5;
        -webkit-backdrop-filter: blur(12px);
        background: #fdfbf4f0;
        border: 1px solid #ffffffe6;
        border-radius: 16px;
        align-items: center;
        gap: 11px;
        min-width: 198px;
        max-width: 245px;
        padding: 11px 15px 11px 11px;
        animation: 7s ease-in-out infinite bob;
        display: flex;
        position: absolute;
        box-shadow: 0 16px 40px -16px #12362a73;
      }

      .float-card strong {
        color: var(--forest-800);
        font-size: 11.5px;
        line-height: 1.3;
        display: block;
      }

      .float-card small {
        color: #79856f;
        margin-top: 2px;
        font-size: 9.5px;
        line-height: 1.35;
        display: block;
      }

      .float-icon {
        background: var(--sage-200);
        width: 34px;
        height: 34px;
        color: var(--forest-700);
        border-radius: 11px;
        flex: 0 0 34px;
        place-items: center;
        display: grid;
      }

      .float-icon svg {
        width: 17px;
        height: 17px;
      }

      .float-card.one {
        bottom: 58px;
        left: 22px;
      }

      .float-card.two {
        animation-delay: -2.3s;
        top: 104px;
        left: 22px;
      }

      .float-card.three {
        animation-delay: -4.6s;
        bottom: 44px;
        right: 22px;
      }

      @keyframes bob {
        0%,
        100% {
          transform: translateY(0);
        }

        50% {
          transform: translateY(-9px);
        }
      }

      @keyframes spin {
        to {
          transform: rotate(360deg);
        }
      }

      @keyframes sway {
        0%,
        100% {
          transform: rotate(-1.1deg);
        }

        50% {
          transform: rotate(1.3deg);
        }
      }

      @keyframes drift {
        0%,
        100% {
          transform: translateX(0);
        }

        50% {
          transform: translateX(6px);
        }
      }

      @keyframes fly {
        from {
          transform: translate(-90px, 26px);
        }

        to {
          transform: translate(620px, -30px);
        }
      }

      @keyframes rise {
        from {
          opacity: 0;
          transform: translateY(30px);
        }

        to {
          opacity: 1;
          transform: none;
        }
      }

      .hero-content > :not(h1):not(.flourish) {
        animation: rise 0.95s var(--ease) both;
      }

      .hero-content > :first-child {
        animation-delay: 50ms;
      }

      .hero-content > :nth-child(4) {
        animation-delay: 0.22s;
      }

      .hero-content > :nth-child(5) {
        animation-delay: 0.32s;
      }

      .hero-content > :nth-child(6) {
        animation-delay: 0.42s;
      }

      .hero-content > :nth-child(7) {
        animation-delay: 0.52s;
      }

      .sun-rays {
        transform-box: fill-box;
        transform-origin: center;
        animation: 90s linear infinite spin;
      }

      .sprout {
        transform-box: fill-box;
        transform-origin: 50% 100%;
        animation: 8s ease-in-out infinite sway;
      }

      .cloud-a {
        animation: 72s ease-in-out infinite drift;
      }

      .cloud-b {
        animation: 88s ease-in-out infinite reverse drift;
      }

      .birds {
        animation: 30s linear infinite fly;
      }

      /* Chickens roaming the field in the hero scene (motion comes from the script at the end of the page) */
      .chicken {
        pointer-events: none;
      }

      /* Sunflower petals: static SVG, moved by the petal script at the end of the page */
      .petals,
      .petal-flight {
        pointer-events: none;
      }

      .marquee {
        border-block: 1px solid var(--line);
        background: var(--paper);
        overflow: hidden;
      }

      .marquee-track {
        width: max-content;
        animation: 34s linear infinite marquee;
        display: flex;
      }

      .marquee:hover .marquee-track {
        animation-play-state: paused;
      }

      .marquee-group {
        align-items: center;
        gap: 30px;
        padding: 15px 30px 15px 0;
        display: flex;
      }

      .marquee-item {
        color: var(--forest-700);
        letter-spacing: 0.24em;
        text-transform: uppercase;
        white-space: nowrap;
        align-items: center;
        gap: 30px;
        font-size: 11px;
        font-weight: 700;
        display: flex;
      }

      .marquee-item svg {
        width: 13px;
        height: 13px;
        color: var(--gold);
        flex: none;
      }

      @keyframes marquee {
        to {
          transform: translateX(-50%);
        }
      }

      .section-pad {
        padding: 100px 0;
      }

      .pillars-layout {
        grid-template-columns: 0.8fr 1.2fr;
        align-items: start;
        gap: clamp(40px, 6vw, 90px);
        display: grid;
      }

      .pillars-intro {
        position: sticky;
        top: 132px;
      }

      .pillars-intro h2 {
        letter-spacing: -0.045em;
        color: var(--forest-800);
        margin: 16px 0;
        font-size: clamp(34px, 3.8vw, 48px);
        line-height: 1.07;
      }

      .pillars-intro p {
        max-width: 380px;
        color: var(--muted);
        font-size: 14px;
        line-height: 1.85;
      }

      .pillars-intro .text-link {
        margin-top: 22px;
      }

      .pillar-list {
        border-top: 1px solid var(--line);
      }

      .pillar-row {
        border-bottom: 1px solid var(--line);
        grid-template-columns: 82px 1fr 64px;
        align-items: center;
        gap: 22px;
        padding: 32px 8px 32px 4px;
        display: grid;
      }

      .pillar-num {
        font-family: var(--font-display);
        color: #bcc6b0;
        transition: color 0.35s var(--ease);
        font-size: 31px;
        font-weight: 500;
      }

      .pillar-row h3 {
        letter-spacing: -0.03em;
        color: var(--forest-800);
        transition:
          transform 0.4s var(--ease),
          color 0.3s var(--ease);
        margin: 0 0 7px;
        font-size: 23px;
        font-weight: 500;
      }

      .pillar-row p {
        color: #68766b;
        max-width: 54ch;
        margin: 0;
        font-size: 12.5px;
        line-height: 1.8;
      }

      .pillar-icon {
        background: var(--sage-200);
        width: 54px;
        height: 54px;
        color: var(--forest-700);
        transition: all 0.4s var(--ease);
        border-radius: 17px;
        place-items: center;
        display: grid;
      }

      .pillar-icon svg {
        width: 23px;
        height: 23px;
      }

      .pillar-row:hover .pillar-num {
        color: var(--gold);
      }

      .pillar-row:hover h3 {
        transform: translateX(5px);
      }

      .pillar-row:hover .pillar-icon {
        background: var(--forest-800);
        color: var(--gold-light);
        transform: rotate(-5deg) scale(1.05);
      }

      .manifesto {
        text-align: center;
        color: #f2f4e8;
        background:
          radial-gradient(900px 420px at 50% -10%, #c9a35c24, transparent 62%),
          linear-gradient(160deg, var(--forest-900), var(--forest-950));
        padding: 130px 0;
        position: relative;
      }

      .manifesto:before {
        content: "";
        background: linear-gradient(90deg, #0000, #e7cf9b99, #0000);
        height: 1px;
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
      }

      .manifesto-text {
        max-width: 920px;
        font-family: var(--font-display);
        letter-spacing: -0.02em;
        margin: 26px auto 0;
        font-size: clamp(27px, 3.6vw, 48px);
        font-weight: 400;
        line-height: 1.32;
      }

      .manifesto-text em {
        font-style: italic;
      }

      .mw {
        color: #e9eede26;
        transition: color 0.5s var(--ease);
      }

      .mw.is-on {
        color: #f2f4e8;
      }

      .manifesto-text em .mw.is-on {
        color: var(--gold-light);
      }

      .manifesto-sign {
        color: var(--gold-light);
        letter-spacing: 0.28em;
        text-transform: uppercase;
        margin: 34px 0 0;
        font-size: 10.5px;
        font-weight: 700;
      }

      .story-band {
        padding: 100px 0;
        scroll-margin-top: -100px;
      }

      .story-card {
        border-radius: var(--radius-lg);
        min-height: 420px;
        box-shadow: var(--shadow);
        background: #edf0e2;
        grid-template-columns: 0.9fr 1.1fr;
        display: grid;
        overflow: hidden;
      }

      .story-art {
        background: linear-gradient(
          165deg,
          var(--forest-800),
          var(--forest-950)
        );
        place-items: center;
        min-height: 360px;
        display: grid;
        position: relative;
      }

      .story-art svg.rings-svg {
        aspect-ratio: 1;
        width: min(78%, 420px);
      }

      .ring {
        fill: none;
        stroke: var(--gold);
        stroke-width: 1.4px;
        stroke-dasharray: 100;
        stroke-dashoffset: 100px;
      }

      .ring.r2 {
        stroke-width: 1.1px;
        opacity: 0.75;
      }

      .ring.r3 {
        stroke-width: 1px;
        opacity: 0.55;
      }

      .ring.r4 {
        stroke-width: 0.9px;
        opacity: 0.4;
      }

      .ring-orbit {
        fill: none;
        stroke: var(--gold-light);
        stroke-width: 1.1px;
        opacity: 0.5;
        stroke-dasharray: 1.5 7;
        transform-box: fill-box;
        transform-origin: center;
        animation: 70s linear infinite spin;
      }

      .rings-svg.is-in .ring {
        transition: stroke-dashoffset 1.9s var(--ease);
      }

      .rings-svg.is-in .r1 {
        transition-delay: 0.1s;
      }

      .rings-svg.is-in .r2 {
        transition-delay: 0.32s;
      }

      .rings-svg.is-in .r3 {
        transition-delay: 0.54s;
      }

      .rings-svg.is-in .r4 {
        transition-delay: 0.76s;
      }

      .rings-svg.is-in .ring {
        stroke-dashoffset: 0;
      }

      .ring-center {
        opacity: 0;
        transform-box: fill-box;
        transform-origin: center;
        transition:
          transform 0.9s var(--ease) 0.9s,
          opacity 0.9s var(--ease) 0.9s;
        transform: scale(0.6);
      }

      .rings-svg.is-in .ring-center {
        opacity: 1;
        transform: scale(1);
      }

      .art-caption {
        color: var(--gold-light);
        letter-spacing: 0.22em;
        text-transform: uppercase;
        opacity: 0.75;
        font-size: 9px;
        font-weight: 700;
        position: absolute;
        bottom: 22px;
        left: 26px;
      }

      .story-copy {
        align-self: center;
        padding: clamp(32px, 5vw, 66px);
      }

      .story-copy h2 {
        letter-spacing: -0.045em;
        max-width: 520px;
        color: var(--forest-800);
        margin: 16px 0 18px;
        font-size: clamp(33px, 3.6vw, 48px);
        line-height: 1.08;
      }

      .story-copy p {
        color: #5f6e60;
        max-width: 500px;
        font-size: 13.5px;
        line-height: 1.9;
      }

      .story-copy p + p {
        margin-top: 14px;
      }

      .steps-section {
        background: var(--paper);
        border-top: 1px solid var(--line);
        border-bottom: 1px solid var(--line);
      }

      .journey {
        max-width: 860px;
        margin: 26px auto 0;
        padding: 8px 0 4px;
        position: relative;
      }

      .journey-track,
      .journey-fill {
        transform-origin: top;
        width: 2px;
        margin-left: -1px;
        position: absolute;
        top: 0;
        bottom: 0;
        left: 50%;
      }

      .journey-track {
        background: var(--line);
      }

      .journey-fill {
        background: linear-gradient(180deg, var(--gold), var(--forest-600));
        transform: scaleY(0);
      }

      .step {
        width: calc(50% - 46px);
        margin-bottom: 42px;
        position: relative;
      }

      article.step:nth-of-type(2n) {
        margin-left: auto;
      }

      .step-dot {
        background: var(--paper);
        z-index: 1;
        width: 13px;
        height: 13px;
        transition: all 0.45s var(--ease);
        border: 2.5px solid #c6d0bd;
        border-radius: 50%;
        position: absolute;
        top: 22px;
      }

      article.step:nth-of-type(odd) .step-dot {
        right: -52.5px;
      }

      article.step:nth-of-type(2n) .step-dot {
        left: -52.5px;
      }

      .step-card {
        background: var(--paper);
        border: 1px solid var(--line);
        transition:
          border-color 0.4s var(--ease),
          box-shadow 0.4s var(--ease);
        border-radius: 20px;
        padding: 24px 26px;
      }

      .step-label {
        color: var(--gold-deep);
        letter-spacing: 0.24em;
        text-transform: uppercase;
        font-size: 10px;
        font-weight: 700;
      }

      .step h3 {
        letter-spacing: -0.02em;
        color: var(--forest-800);
        margin: 8px 0;
        font-size: 21px;
        font-weight: 500;
      }

      .step p {
        color: #68766b;
        margin: 0;
        font-size: 12.5px;
        line-height: 1.8;
      }

      .step.is-active .step-card {
        border-color: #cbd9bc;
        box-shadow: 0 20px 44px -26px #12362a66;
      }

      .step.is-active .step-dot {
        background: var(--gold);
        border-color: var(--forest-700);
        transform: scale(1.2);
      }

      .profit-section {
        color: #fff;
        background:
          radial-gradient(820px 440px at 84% 6%, #c9a35c29, transparent 60%),
          radial-gradient(700px 480px at 4% 96%, #2c5a448c, transparent 62%),
          linear-gradient(155deg, var(--forest-800), var(--forest-950));
        padding: 100px 0;
        position: relative;
        overflow: hidden;
      }

      .profit-section:after {
        content: "";
        pointer-events: none;
        border: 1px solid #dee4d129;
        border-radius: 50%;
        width: 520px;
        height: 520px;
        position: absolute;
        bottom: -270px;
        right: -210px;
        box-shadow:
          0 0 0 40px #dee4d10a,
          0 0 0 82px #dee4d108;
      }

      .profit-grid {
        z-index: 1;
        grid-template-columns: 1.06fr 0.94fr;
        align-items: center;
        gap: clamp(40px, 6vw, 76px);
        display: grid;
        position: relative;
      }

      .profit-section h2 {
        letter-spacing: -0.045em;
        color: #fdfcf5;
        max-width: 600px;
        margin: 18px 0 22px;
        font-size: clamp(36px, 4vw, 54px);
        line-height: 1.05;
      }

      .profit-section .lead {
        color: #ccd9ca;
        max-width: 560px;
        font-size: 14.5px;
        line-height: 1.9;
      }

      .profit-section .button {
        margin-top: 18px;
      }

      .profit-panel {
        -webkit-backdrop-filter: blur(10px);
        background: #ffffff0d;
        border: 1px solid #ffffff26;
        border-radius: 28px;
        padding: 34px;
        position: relative;
      }

      .panel-label {
        color: var(--gold-light);
        letter-spacing: 0.24em;
        text-transform: uppercase;
        font-size: 9.5px;
        font-weight: 700;
      }

      .pool {
        aspect-ratio: 1;
        width: 198px;
        margin: 22px auto 10px;
        position: relative;
      }

      .pool:before {
        content: "";
        border: 1px solid #e7cf9b4d;
        border-radius: 50%;
        position: absolute;
        inset: 0;
      }

      .pool:after {
        content: "";
        border: 1px dashed #e7cf9b4d;
        border-radius: 50%;
        animation: 46s linear infinite spin;
        position: absolute;
        inset: 27px;
      }

      .orbit {
        border-radius: 50%;
        animation: 22s linear infinite spin;
        position: absolute;
        inset: -13px;
      }

      .orbit:before {
        content: "";
        background: var(--gold-light);
        border-radius: 50%;
        width: 10px;
        height: 10px;
        margin-left: -5px;
        position: absolute;
        top: -5px;
        left: 50%;
      }

      .orbit.o2 {
        animation: 32s linear -9s infinite reverse spin;
        inset: 9px;
      }

      .orbit.o2:before {
        background: #e7cf9b99;
        width: 6px;
        height: 6px;
        margin-left: -3px;
        top: -3px;
      }

      .pool-center {
        background: var(--gold);
        text-align: center;
        color: var(--forest-900);
        font-family: var(--font-display);
        border-radius: 50%;
        place-items: center;
        font-size: 15.5px;
        font-weight: 500;
        line-height: 1.25;
        display: grid;
        position: absolute;
        inset: 53px;
        box-shadow: 0 14px 34px -12px #00000073;
      }

      .profit-points {
        border-top: 1px solid #ffffff24;
        gap: 13px;
        margin: 20px 0 0;
        padding-top: 20px;
        list-style: none;
        display: grid;
      }

      .profit-points li {
        color: #e4e9df;
        gap: 11px;
        font-size: 11.5px;
        line-height: 1.65;
        display: flex;
      }

      .profit-points li strong {
        color: #fff;
      }

      .profit-points svg {
        width: 15px;
        height: 15px;
        color: var(--gold-light);
        flex: none;
        margin-top: 2px;
      }

      .disclaimer {
        color: #a9bfa9;
        margin: 22px 0 0;
        font-size: 10px;
        line-height: 1.75;
      }

      .faq-layout {
        grid-template-columns: 0.72fr 1.28fr;
        align-items: start;
        gap: clamp(40px, 6vw, 72px);
        display: grid;
      }

      .faq-layout .section-head {
        margin-bottom: 0;
        position: sticky;
        top: 132px;
      }

      .faq-list {
        border-top: 1px solid var(--line);
      }

      .faq-list details {
        border-bottom: 1px solid var(--line);
      }

      .faq-list summary {
        cursor: pointer;
        color: var(--forest-800);
        font-family: var(--font-display);
        letter-spacing: -0.015em;
        transition:
          color 0.25s var(--ease),
          padding 0.3s var(--ease);
        justify-content: space-between;
        align-items: center;
        gap: 22px;
        padding: 21px 4px;
        font-size: 18px;
        font-weight: 500;
        line-height: 1.4;
        list-style: none;
        display: flex;
      }

      .faq-list summary::-webkit-details-marker {
        display: none;
      }

      .faq-list summary:hover {
        color: var(--forest-600);
        padding-left: 8px;
      }

      .faq-plus {
        width: 30px;
        height: 30px;
        transition: background 0.3s var(--ease);
        background: #e9eee0;
        border-radius: 50%;
        flex: 0 0 30px;
        position: relative;
      }

      .faq-plus:before,
      .faq-plus:after {
        content: "";
        background: var(--forest-800);
        transition:
          transform 0.35s var(--ease),
          opacity 0.35s var(--ease),
          background 0.3s var(--ease);
        border-radius: 2px;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
      }

      .faq-plus:before {
        width: 12px;
        height: 1.8px;
      }

      .faq-plus:after {
        width: 1.8px;
        height: 12px;
      }

      details[open] .faq-plus {
        background: var(--forest-800);
      }

      details[open] .faq-plus:before,
      details[open] .faq-plus:after {
        background: #fff;
      }

      details[open] .faq-plus:after {
        opacity: 0;
        transform: translate(-50%, -50%) rotate(90deg);
      }

      .faq-list details p {
        color: #68766b;
        animation: fadeSlide 0.45s var(--ease);
        margin: 0;
        padding: 0 48px 24px 4px;
        font-size: 12.5px;
        line-height: 1.9;
      }

      @keyframes fadeSlide {
        from {
          opacity: 0;
          transform: translateY(-6px);
        }

        to {
          opacity: 1;
          transform: none;
        }
      }

      .contact-section {
        padding: 0 0 100px;
      }

      .contact-grid {
        grid-template-columns: minmax(0, 1.02fr) minmax(0, 1fr);
        align-items: stretch;
        gap: clamp(18px, 3vw, 30px);
        display: grid;
      }

      .contact-box {
        background: linear-gradient(
          165deg,
          var(--forest-800),
          var(--forest-950)
        );
        color: #e9eede;
        box-shadow: var(--shadow);
        border-radius: 22px;
        flex-direction: column;
        padding: clamp(26px, 3.4vw, 40px);
        display: flex;
      }

      .contact-box h3 {
        letter-spacing: -0.03em;
        color: #fdfcf5;
        margin: 0;
        font-size: clamp(24px, 2.4vw, 30px);
        line-height: 1.15;
      }

      .contact-box > p {
        color: #ffffffa3;
        max-width: 44ch;
        margin: 12px 0 0;
        font-size: 13.5px;
        line-height: 1.8;
      }

      .contact-details {
        margin-top: auto;
        padding-top: 18px;
        display: grid;
      }

      .contact-detail {
        border-top: 1px solid #ffffff1a;
        gap: 4px;
        padding: 13px 0;
        display: grid;
      }

      .cd-label {
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: #ffffff6b;
        font-size: 9.5px;
        font-weight: 700;
      }

      .cd-value {
        color: #fff;
        font-size: 13px;
        line-height: 1.65;
      }

      .cd-value a {
        transition:
          border-color 0.25s var(--ease),
          color 0.25s var(--ease);
        border-bottom: 1px solid #e7cf9b73;
      }

      .cd-value a:hover {
        color: var(--gold-light);
        border-color: var(--gold-light);
      }

      .cd-note {
        color: #ffffff80;
        font-size: 11px;
      }

      .map-col {
        flex-direction: column;
        gap: 14px;
        display: flex;
      }

      .map-frame {
        border: 1px solid var(--line);
        min-height: 480px;
        box-shadow: var(--shadow);
        background: #e3e9d6;
        border-radius: 22px;
        flex: auto;
        position: relative;
        overflow: hidden;
      }

      .map-frame iframe {
        filter: saturate(0.78) contrast(1.02);
        width: 100%;
        height: 100%;
        min-height: 480px;
        transition: filter 0.6s var(--ease);
        border: 0;
        display: block;
      }

      .map-frame:hover iframe,
      .map-frame:focus-within iframe {
        filter: saturate();
      }

      .map-meta {
        color: var(--muted);
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 10px 16px;
        font-size: 12px;
        line-height: 1.6;
        display: flex;
      }

      .map-meta svg {
        width: 14px;
        height: 14px;
        color: var(--forest-600);
        vertical-align: -2px;
        flex: none;
        margin-right: 6px;
      }

      .map-actions {
        flex-wrap: wrap;
        gap: 8px;
        display: flex;
      }

      .map-actions a {
        border: 1px solid var(--line);
        background: var(--paper);
        color: var(--forest-800);
        border-radius: 999px;
        align-items: center;
        gap: 7px;
        padding: 9px 16px;
        font-size: 12px;
        font-weight: 600;
        transition:
          background 0.2s,
          border-color 0.2s,
          transform 0.2s;
        display: inline-flex;
      }

      .map-actions a:hover {
        background: var(--sage-200);
        border-color: var(--forest-600);
        transform: translateY(-1px);
      }

      .map-actions svg {
        width: 13px;
        height: 13px;
      }

      .inquiry-form {
        background: var(--paper);
        border: 1px solid var(--line);
        border-radius: 22px;
        margin-top: 26px;
        padding: clamp(26px, 3.4vw, 42px);
        box-shadow: 0 18px 50px -32px #12362a59;
      }

      .inquiry-form h3 {
        letter-spacing: -0.03em;
        color: var(--forest-800);
        margin-bottom: 6px;
        font-size: 24px;
        font-weight: 500;
      }

      .form-intro {
        color: #7a857a;
        margin-bottom: 22px;
        font-size: 11.5px;
      }

      .field-row {
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        display: grid;
      }

      .field {
        gap: 7px;
        margin-bottom: 16px;
        display: grid;
      }

      .field label {
        color: #4f6252;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        font-size: 10.5px;
        font-weight: 700;
      }

      .field input,
      .field select,
      .field textarea {
        width: 100%;
        transition:
          border-color 0.25s var(--ease),
          box-shadow 0.25s var(--ease),
          background 0.25s var(--ease);
        background: #fffef9;
        border: 1px solid #dfe5d9;
        border-radius: 12px;
        outline: none;
        padding: 13px 14px;
        font-size: 13px;
      }

      .field input::placeholder,
      .field textarea::placeholder {
        color: #a9b2a6;
      }

      .field input:focus,
      .field select:focus,
      .field textarea:focus {
        background: #fff;
        border-color: #7d9878;
        box-shadow: 0 0 0 4px #7d987829;
      }

      .field select {
        appearance: none;
        cursor: pointer;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%235c6b5f' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E");
        background-position: right 14px center;
        background-repeat: no-repeat;
      }

      .field textarea {
        resize: vertical;
        min-height: 92px;
      }

      .field.is-error input,
      .field.is-error textarea {
        border-color: #b4552e;
        box-shadow: 0 0 0 4px #b4552e1f;
      }

      .field-error {
        color: #a34a26;
        min-height: 0;
        font-size: 10.5px;
        line-height: 1.4;
      }

      .form-submit {
        border: 0;
        width: 100%;
        margin-top: 6px;
      }

      .btn-spinner {
        opacity: 0;
        border: 2px solid #ffffff59;
        border-top-color: #fff;
        border-radius: 50%;
        width: 18px;
        height: 18px;
        animation: 0.8s linear infinite spin;
        position: absolute;
      }

      .form-submit.is-loading .btn-label,
      .form-submit.is-loading .btn-send {
        opacity: 0;
      }

      .form-submit.is-loading .btn-spinner {
        opacity: 1;
      }

      .form-note {
        color: #8e978a;
        margin: 14px 0 0;
        font-size: 9.5px;
        line-height: 1.65;
      }

      .form-success {
        text-align: center;
        justify-items: center;
        gap: 8px;
        padding: 34px 10px;
        display: grid;
      }

      .success-icon {
        background: var(--sage-200);
        width: 54px;
        height: 54px;
        color: var(--forest-600);
        border-radius: 50%;
        place-items: center;
        margin-bottom: 8px;
        display: grid;
      }

      .success-icon svg {
        width: 24px;
        height: 24px;
      }

      .form-success strong {
        font-family: var(--font-display);
        color: var(--forest-800);
        font-size: 22px;
        font-weight: 500;
      }

      .form-success p {
        color: #68766b;
        max-width: 38ch;
        margin: 0;
        font-size: 12.5px;
        line-height: 1.8;
      }

      .final-cta {
        border-top: 1px solid var(--line);
        background: #eef1e6;
        padding: 64px 0 76px;
      }

      .final-cta-inner {
        justify-content: space-between;
        align-items: center;
        gap: 28px;
        display: flex;
      }

      .final-cta h2 {
        letter-spacing: -0.04em;
        color: var(--forest-800);
        margin: 0;
        font-size: clamp(28px, 3.4vw, 42px);
        line-height: 1.08;
      }

      .final-cta h2 em {
        color: #4c7257;
        font-style: italic;
        font-weight: 400;
      }

      .final-cta p {
        color: var(--muted);
        max-width: 56ch;
        margin: 10px 0 0;
        font-size: 13.5px;
        line-height: 1.75;
      }

      .final-cta .button {
        flex: none;
        padding: 17px 30px;
        font-size: 13px;
      }

      .site-footer {
        color: #d8e0d4;
        background: linear-gradient(#0f2614, #0a1d0f);
        padding: 58px 0 26px;
        position: relative;
      }

      .site-footer:before {
        content: "";
        background: linear-gradient(90deg, #0000, #e7cf9b66, #0000);
        height: 1px;
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
      }

      .footer-top {
        border-bottom: 1px solid #ffffff17;
        grid-template-columns: 1.25fr 0.62fr 0.62fr 0.95fr;
        gap: clamp(26px, 4vw, 54px);
        padding-bottom: 38px;
        display: grid;
      }

      .footer-brand .brand-name {
        color: #fdfbf4;
      }

      .footer-brand .brand-sub {
        color: #b9c9b3;
      }

      .footer-brand .brand-mark {
        box-shadow: 0 8px 22px -10px #000c;
      }

      .footer-summary {
        color: #b0c1b1;
        max-width: 300px;
        margin-top: 18px;
        font-size: 11.5px;
        line-height: 1.8;
      }

      .social-row {
        gap: 10px;
        margin-top: 20px;
        display: flex;
      }

      .social-btn {
        color: #e7cf9b;
        width: 40px;
        height: 40px;
        transition:
          background 0.3s var(--ease),
          color 0.3s var(--ease),
          transform 0.3s var(--ease),
          border-color 0.3s var(--ease);
        border: 1px solid #e7cf9b59;
        border-radius: 50%;
        place-items: center;
        display: grid;
      }

      .social-btn:hover {
        background: var(--gold);
        border-color: var(--gold);
        color: var(--forest-900);
        transform: translateY(-2px);
      }

      .social-btn svg {
        width: 15px;
        height: 15px;
      }

      .footer-col strong {
        color: #fff;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        margin-bottom: 15px;
        font-size: 9.5px;
        display: block;
      }

      .footer-col a {
        color: #cbd8c9;
        transition:
          color 0.25s var(--ease),
          transform 0.25s var(--ease);
        margin: 9px 0;
        font-size: 11.5px;
        display: block;
      }

      .footer-col a:hover {
        color: #fff;
        transform: translateX(3px);
      }

      .footer-contact address {
        font-style: normal;
        display: grid;
      }

      .fc-item {
        color: #cbd8c9;
        border-top: 1px solid #ffffff1a;
        align-items: flex-start;
        gap: 11px;
        padding: 10px 0;
        font-size: 11.5px;
        line-height: 1.7;
        display: flex;
      }

      .fc-item:first-child {
        border-top: 0;
        padding-top: 0;
      }

      .fc-item svg {
        width: 15px;
        height: 15px;
        color: var(--gold-light);
        flex: none;
        margin-top: 2px;
      }

      .footer-contact a {
        color: #cbd8c9;
        transition: color 0.25s var(--ease);
        border-top: 1px solid #ffffff1a;
        align-items: flex-start;
        gap: 11px;
        margin: 0;
        padding: 10px 0;
        font-size: 11.5px;
        line-height: 1.7;
        display: flex;
      }

      .footer-contact a:hover {
        color: #fff;
        transform: none;
      }

      .footer-contact a svg {
        width: 15px;
        height: 15px;
        color: var(--gold-light);
        flex: none;
        margin-top: 2px;
      }

      .footer-contact .fc-item a {
        color: inherit;
        border: 0;
        padding: 0;
        display: inline;
      }

      .footer-contact .fc-item a:hover {
        color: #fff;
        text-decoration: underline;
      }

      .utility-bar a {
        color: inherit;
        text-decoration: none;
      }

      .utility-bar a:hover {
        text-decoration: underline;
      }

      .footer-bottom {
        color: #93a894;
        justify-content: space-between;
        gap: 24px;
        padding-top: 24px;
        font-size: 11px;
        line-height: 1.75;
        display: flex;
      }

      .footer-disclaimer {
        text-align: right;
        max-width: 680px;
      }

      .toast {
        z-index: 320;
        opacity: 0;
        pointer-events: none;
        background: var(--forest-900);
        color: #f2f4e8;
        max-width: min(92vw, 440px);
        transition:
          opacity 0.45s var(--ease),
          transform 0.45s var(--ease);
        border: 1px solid #e7cf9b47;
        border-radius: 999px;
        align-items: center;
        gap: 10px;
        padding: 13px 20px;
        font-size: 12.5px;
        display: flex;
        position: fixed;
        bottom: 26px;
        left: 50%;
        transform: translate(-50%, 20px);
        box-shadow: 0 18px 40px -12px #00000073;
      }

      .toast svg {
        width: 16px;
        height: 16px;
        color: var(--gold-light);
        flex: none;
      }

      .toast.is-visible {
        opacity: 1;
        transform: translate(-50%);
      }

      .to-top {
        z-index: 210;
        cursor: pointer;
        background: var(--gold);
        width: 50px;
        height: 50px;
        color: var(--forest-900);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition:
          opacity 0.4s var(--ease),
          transform 0.4s var(--ease),
          background 0.3s var(--ease),
          visibility 0s linear 0.4s;
        border: 0;
        border-radius: 50%;
        place-items: center;
        display: grid;
        position: fixed;
        bottom: 26px;
        right: 26px;
        transform: translateY(14px);
        box-shadow: 0 14px 30px -10px #12362a80;
      }

      .to-top.is-visible {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transition-delay: 0s;
        transform: none;
      }

      .to-top:focus-visible {
        outline: 2px solid var(--forest-900);
        outline-offset: 3px;
        box-shadow: 0 0 0 6px var(--gold-light);
      }

      #home:focus {
        outline: none;
      }

      .to-top:hover {
        background: var(--gold-light);
        transform: translateY(-3px);
      }

      .to-top svg {
        width: 18px;
        height: 18px;
      }

      .mobile-cta {
        display: none;
      }

      .js [data-reveal] {
        opacity: 0;
        transition:
          opacity 0.9s var(--ease),
          transform 0.9s var(--ease);
        transition-delay: var(--d, 0s);
        transform: translateY(28px);
      }

      .js [data-reveal].is-in {
        opacity: 1;
        transform: none;
      }

      @media (width <= 980px) {
        .hero-grid {
          grid-template-columns: 1fr 0.94fr;
          gap: 34px;
        }

        .hero-visual {
          min-height: 440px;
        }

        .float-card.three {
          bottom: 96px;
          right: 16px;
        }

        .pillars-layout {
          grid-template-columns: 1fr;
          gap: 38px;
        }

        .pillars-intro {
          position: static;
        }

        .profit-grid {
          gap: 44px;
        }

        .faq-layout {
          grid-template-columns: 1fr;
          gap: 36px;
        }

        .faq-layout .section-head {
          margin-bottom: 26px;
          position: static;
        }

        .contact-grid {
          grid-template-columns: 1fr;
          gap: 22px;
        }

        .map-frame,
        .map-frame iframe {
          min-height: 420px;
        }

        .footer-top {
          grid-template-columns: 1fr 1fr;
          gap: 34px;
        }

        .footer-brand {
          grid-column: 1 / -1;
        }

        /* Two CTAs in the header: tighten spacing before the nav collapses */
        .nav-links {
          gap: 14px;
        }

        .nav-links .button {
          padding: 11px 15px;
        }
      }

      @media (width <= 760px) {
        .utility-left span:not(:first-child),
        .utility-right span:not(:first-child) {
          display: none;
        }

        .utility-inner {
          min-height: 30px;
          font-size: 10px;
        }

        .nav-wrap {
          justify-content: space-between;
          gap: 16px;
          padding: 12px 20px;
        }

        .brand-mark {
          flex-basis: 42px;
          width: 42px;
          height: 42px;
        }

        .brand-name {
          font-size: 22px;
        }

        .menu-toggle {
          display: inline-flex;
        }

        .nav-links {
          display: none;
        }

        .container {
          width: min(100% - 32px, 600px);
        }

        .hero {
          padding: 52px 0 64px;
        }

        .hero-grid {
          grid-template-columns: 1fr;
          gap: 38px;
        }

        h1 {
          font-size: clamp(44px, 13vw, 64px);
        }

        .hero-visual {
          min-height: min(112vw, 480px);
        }

        .float-card {
          min-width: 178px;
        }

        .float-card strong {
          font-size: 10.5px;
        }

        .float-card.one {
          bottom: 60px;
          left: 14px;
        }

        .float-card.two {
          top: 96px;
          left: 13px;
        }

        .float-card.three {
          bottom: 110px;
          right: 13px;
        }

        .hero-stats {
          grid-template-columns: 1fr;
          gap: 0;
          margin-top: 26px;
        }

        .hero-stat {
          border-bottom: 1px solid var(--line);
          align-items: baseline;
          gap: 14px;
          padding: 11px 0;
          display: flex;
        }

        .hero-stat:last-child {
          border-bottom: 0;
        }

        .hero-stat span {
          max-width: none;
          margin-top: 0;
        }

        .manifesto {
          padding: 88px 0;
        }

        .section-pad {
          padding: 68px 0;
        }

        .section-head {
          margin-bottom: 32px;
        }

        .section-head h2 {
          font-size: clamp(34px, 9.5vw, 44px);
        }

        .pillar-row {
          align-items: flex-start;
          gap: 16px;
          padding: 24px 0;
          display: flex;
        }

        .pillar-num {
          display: none;
        }

        .pillar-icon {
          border-radius: 14px;
          flex: 0 0 46px;
          order: -1;
          width: 46px;
          height: 46px;
          display: grid;
        }

        .story-band {
          padding-top: 68px;
          padding-bottom: 68px;
          scroll-margin-top: -68px;
        }

        .story-card {
          grid-template-columns: 1fr;
        }

        .story-art {
          min-height: 330px;
        }

        .story-copy {
          padding: 34px 26px 38px;
        }

        .story-copy h2 {
          font-size: 36px;
        }

        .journey-track,
        .journey-fill {
          margin-left: 0;
          left: 9px;
        }

        .step {
          width: 100%;
          margin-bottom: 28px;
          padding-left: 44px;
        }

        article.step:nth-of-type(odd) .step-dot,
        article.step:nth-of-type(2n) .step-dot {
          left: 3px;
          right: auto;
        }

        .profit-section {
          padding: 68px 0;
        }

        .profit-grid {
          grid-template-columns: 1fr;
          gap: 36px;
        }

        .profit-section h2 {
          font-size: 38px;
        }

        .contact-section {
          padding-bottom: 68px;
        }

        .contact-box {
          padding: 24px 20px;
        }

        .map-frame,
        .map-frame iframe {
          min-height: 360px;
        }

        .map-meta {
          flex-direction: column;
          align-items: flex-start;
          gap: 10px;
        }

        .inquiry-form {
          padding: 26px 20px;
        }

        .field-row {
          grid-template-columns: 1fr;
        }

        .final-cta {
          padding: 58px 0;
        }

        .final-cta-inner {
          flex-direction: column;
          align-items: flex-start;
          gap: 18px;
        }

        .footer-bottom {
          flex-direction: column;
        }

        .footer-disclaimer {
          text-align: left;
        }

        .to-top {
          right: 14px;
          bottom: calc(88px + env(safe-area-inset-bottom, 0px));
          width: 46px;
          height: 46px;
        }

        .to-top svg {
          width: 16px;
          height: 16px;
        }

        .toast {
          bottom: calc(92px + env(safe-area-inset-bottom, 0px));
        }

        .mobile-cta {
          z-index: 180;
          left: 12px;
          right: 12px;
          bottom: calc(10px + env(safe-area-inset-bottom, 0px));
          -webkit-backdrop-filter: blur(16px);
          background: #fdfbf4f2;
          border: 1px solid #e2e7dc;
          border-radius: 999px;
          gap: 8px;
          padding: 8px;
          display: flex;
          position: fixed;
          box-shadow: 0 14px 36px -12px #12362a80;
        }

        .mobile-cta .button {
          flex: 1;
          padding: 13px 14px;
        }

        .site-footer {
          padding-bottom: calc(104px + env(safe-area-inset-bottom, 0px));
        }
      }

      @media (width <= 640px) {
        .container,
        .utility-inner {
          width: min(100% - 24px, 600px);
        }

        .section-pad {
          padding: 58px 0;
        }

        .story-band {
          padding-top: 58px;
          padding-bottom: 58px;
          scroll-margin-top: -58px;
        }

        .profit-section {
          padding: 58px 0;
        }

        .contact-section {
          padding-bottom: 58px;
        }

        .hero-visual,
        .story-card,
        .map-frame,
        .contact-box,
        .inquiry-form {
          border-radius: 18px;
        }

        .footer-top {
          grid-template-columns: 1fr;
          gap: 32px;
        }
      }

      @media (width <= 400px) {
        .hero-actions .button {
          width: 100%;
        }

        .float-card {
          min-width: 0;
          padding: 9px 11px;
        }

        .float-icon {
          border-radius: 9px;
          flex-basis: 28px;
          width: 28px;
          height: 28px;
        }

        .float-icon svg {
          width: 14px;
          height: 14px;
        }

        .map-actions {
          width: 100%;
        }

        .map-actions a {
          flex: 1;
          justify-content: center;
        }
      }

      @media (prefers-reduced-motion: reduce) {
        html {
          scroll-behavior: auto;
        }

        *,
        :before,
        :after {
          transition-duration: 0.01ms !important;
          animation-duration: 0.01ms !important;
          animation-iteration-count: 1 !important;
        }

        .js [data-reveal] {
          opacity: 1;
          transform: none;
        }
      }

      :root {
        --mark-bg: #10281d;
        --mark-cream: #f7f6ee;
        --mark-leaf: #9fb98e;
        --mark-gold: #e4c98a;
        --mark-kernel: #7d5e22;
        --mark-ring: #c9a35c;
      }

      .brand-mark {
        overflow: visible;
      }

      .seeds {
        z-index: 2;
        pointer-events: none;
        position: absolute;
        inset: 0;
        overflow: hidden;
      }

      .seeds i {
        left: calc(6% + (var(--i) * 6.4%));
        width: 7px;
        height: 3.4px;
        animation: seedUp calc(11s + var(--i) * 0.7s) linear infinite;
        animation-delay: calc(var(--i) * -1.15s);
        opacity: 0.75;
        background: #c9a35c8c;
        border-radius: 50%;
        position: absolute;
        bottom: -8%;
      }

      .seeds i:nth-child(3n) {
        background: #e9eedeb3;
        width: 5px;
        height: 2.6px;
      }

      .seeds i:nth-child(3n + 1) {
        background: #78966e8c;
        width: 8px;
        height: 3.8px;
      }

      @keyframes seedUp {
        0% {
          transform: translate3d(0, 20px, 0) rotate(0);
        }

        100% {
          transform: translate3d(16px, -640px, 0) rotate(28deg);
        }
      }

      @media (prefers-reduced-motion: reduce) {
        .seeds {
          display: none;
        }
      }

      @property --tw-rotate-x {
        syntax: "*";
        inherits: false;
      }

      @property --tw-rotate-y {
        syntax: "*";
        inherits: false;
      }

      @property --tw-rotate-z {
        syntax: "*";
        inherits: false;
      }

      @property --tw-skew-x {
        syntax: "*";
        inherits: false;
      }

      @property --tw-skew-y {
        syntax: "*";
        inherits: false;
      }

      @property --tw-border-style {
        syntax: "*";
        inherits: false;
        initial-value: solid;
      }

      @property --tw-font-weight {
        syntax: "*";
        inherits: false;
      }

      @property --tw-shadow {
        syntax: "*";
        inherits: false;
        initial-value: 0 0 #0000;
      }

      @property --tw-shadow-color {
        syntax: "*";
        inherits: false;
      }

      @property --tw-shadow-alpha {
        syntax: "<percentage>";
        inherits: false;
        initial-value: 100%;
      }

      @property --tw-inset-shadow {
        syntax: "*";
        inherits: false;
        initial-value: 0 0 #0000;
      }

      @property --tw-inset-shadow-color {
        syntax: "*";
        inherits: false;
      }

      @property --tw-inset-shadow-alpha {
        syntax: "<percentage>";
        inherits: false;
        initial-value: 100%;
      }

      @property --tw-ring-color {
        syntax: "*";
        inherits: false;
      }

      @property --tw-ring-shadow {
        syntax: "*";
        inherits: false;
        initial-value: 0 0 #0000;
      }

      @property --tw-inset-ring-color {
        syntax: "*";
        inherits: false;
      }

      @property --tw-inset-ring-shadow {
        syntax: "*";
        inherits: false;
        initial-value: 0 0 #0000;
      }

      @property --tw-ring-inset {
        syntax: "*";
        inherits: false;
      }

      @property --tw-ring-offset-width {
        syntax: "<length>";
        inherits: false;
        initial-value: 0;
      }

      @property --tw-ring-offset-color {
        syntax: "*";
        inherits: false;
        initial-value: #fff;
      }

      @property --tw-ring-offset-shadow {
        syntax: "*";
        inherits: false;
        initial-value: 0 0 #0000;
      }

      /* ---- Mobile hero fix: keep the hero card, copy and CTA inside the viewport ---- */
      @media (max-width: 980px) {
        .hero-grid {
          grid-template-columns: minmax(0, 1fr) minmax(0, 0.94fr);
        }
        .hero-content,
        .hero-visual-wrap {
          min-width: 0;
        }
        .hero-visual {
          min-width: 0;
          min-height: 0;
        }
        .float-card {
          min-width: 0;
          max-width: calc(100% - 28px);
        }
        .float-card.one {
          top: 7%;
          bottom: auto;
          left: 14px;
          right: auto;
        }
        .float-card.two {
          top: 29%;
          bottom: auto;
          left: 14px;
          right: auto;
        }
        .float-card.three {
          top: 51%;
          bottom: auto;
          left: 14px;
          right: auto;
        }
      }
      @media (max-width: 760px) {
        .mobile-cta {
          transition:
            transform 0.35s var(--ease),
            opacity 0.35s var(--ease);
        }
        .mobile-cta.is-away {
          transform: translateY(140%);
          opacity: 0;
          pointer-events: none;
        }
        .hero-grid {
          grid-template-columns: minmax(0, 1fr);
        }
        .hero-copy,
        .hero-stats {
          max-width: 100%;
        }
        .hero-visual {
          width: 100%;
        }
      }
      @media (max-width: 400px) {
        .art-kicker {
          left: 28px;
          font-size: 8.5px;
          letter-spacing: 0.12em;
        }
      }
    </style>
  </head>
  <body>
    <div id="altas-root">
      <a class="skip-link" href="#main">Skip to content</a>
      <div class="scroll-progress" aria-hidden="true">
        <span id="scroll-progress"></span>
      </div>

      <!-- Utility bar (reference pattern: location · contact · hours) -->
      <div class="utility-bar">
        <div class="utility-inner">
          <div class="utility-left">
            <span>Wellness &amp; Livelihood Community</span>
            <span class="dot" aria-hidden="true">•</span>
            <span>Based in <strong>Cabatuan, Isabela</strong></span>
          </div>
          <div class="utility-right">
            <a href="mailto:contact@altasfarm.life"
              ><strong>contact@altasfarm.life</strong></a
            >
            <span class="dot" aria-hidden="true">•</span>
            <span>Mon–Sat · 9 AM–6 PM</span>
          </div>
        </div>
      </div>

      <header class="site-header">
        <div class="nav-wrap">
          <a
            class="brand"
            href="#home"
            aria-label="AltasFarm Livelihood — back to top"
          >
            <svg
              class="brand-mark"
              viewBox="0 0 64 64"
              aria-hidden="true"
              focusable="false"
            >
              <rect width="64" height="64" rx="18" fill="#10281d" />
              <rect
                x="2"
                y="2"
                width="60"
                height="60"
                rx="16"
                fill="none"
                stroke="#c9a35c"
                stroke-width="1.15"
                opacity="0.62"
              />
              <path
                d="M21 46.5 30.6 19.2"
                stroke="#f7f6ee"
                stroke-width="3.4"
                stroke-linecap="round"
              />
              <path
                d="M43 46.5 33.4 19.2"
                stroke="#f7f6ee"
                stroke-width="3.4"
                stroke-linecap="round"
              />
              <g
                transform="translate(21.99 23.98) scale(0.82)"
                fill="none"
                stroke="#9fb98e"
                stroke-width="2.4"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path
                  d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  fill="#10281d"
                />
                <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12" />
              </g>
              <circle cx="32" cy="15.8" r="4.15" fill="#e4c98a" />
              <circle cx="32" cy="15.8" r="1.7" fill="#7d5e22" />
              <path
                d="M18.5 47.2h7"
                stroke="#f7f6ee"
                stroke-width="2.6"
                stroke-linecap="round"
              />
              <path
                d="M38.5 47.2h7"
                stroke="#f7f6ee"
                stroke-width="2.6"
                stroke-linecap="round"
              />
            </svg>
            <span>
              <span class="brand-name">AltasFarm</span>
              <span class="brand-sub">Livelihood</span>
            </span>
          </a>

          <button
            class="menu-toggle"
            type="button"
            aria-label="Open navigation"
            aria-expanded="false"
            aria-controls="mobile-nav"
          >
            <svg
              width="22"
              height="22"
              viewBox="0 0 24 24"
              fill="none"
              aria-hidden="true"
            >
              <path
                d="M4 7h16M4 12h16M4 17h16"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
              />
            </svg>
          </button>

          <nav class="nav-links" id="main-nav" aria-label="Main navigation">
            <a href="#approach">Our approach</a>
            <a href="#how-it-works">How it works</a>
            <a href="#opportunity">Opportunity</a>
            <a href="#faq">FAQ</a>
            <a class="button button-ghost nav-login" href="<?= e($base) ?>/?page=login"
              >Login</a
            >
            <a class="button button-primary" href="<?= e($base) ?>/?page=register"
              >Join free
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path
                  d="M5 12h14M12 5l7 7-7 7"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </a>
          </nav>
        </div>
      </header>

      <!-- Full-screen mobile menu (dark panel — reference pattern) -->
      <div class="mobile-nav" id="mobile-nav" aria-hidden="true">
        <div class="mobile-nav-head">
          <span class="brand">
            <svg
              class="brand-mark"
              viewBox="0 0 64 64"
              aria-hidden="true"
              focusable="false"
            >
              <rect width="64" height="64" rx="18" fill="#10281d" />
              <rect
                x="2"
                y="2"
                width="60"
                height="60"
                rx="16"
                fill="none"
                stroke="#c9a35c"
                stroke-width="1.15"
                opacity="0.62"
              />
              <path
                d="M21 46.5 30.6 19.2"
                stroke="#f7f6ee"
                stroke-width="3.4"
                stroke-linecap="round"
              />
              <path
                d="M43 46.5 33.4 19.2"
                stroke="#f7f6ee"
                stroke-width="3.4"
                stroke-linecap="round"
              />
              <g
                transform="translate(21.99 23.98) scale(0.82)"
                fill="none"
                stroke="#9fb98e"
                stroke-width="2.4"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path
                  d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  fill="#10281d"
                />
                <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12" />
              </g>
              <circle cx="32" cy="15.8" r="4.15" fill="#e4c98a" />
              <circle cx="32" cy="15.8" r="1.7" fill="#7d5e22" />
              <path
                d="M18.5 47.2h7"
                stroke="#f7f6ee"
                stroke-width="2.6"
                stroke-linecap="round"
              />
              <path
                d="M38.5 47.2h7"
                stroke="#f7f6ee"
                stroke-width="2.6"
                stroke-linecap="round"
              />
            </svg>
            <span>
              <span class="brand-name">AltasFarm</span>
              <span class="brand-sub">Livelihood</span>
            </span>
          </span>
          <button
            class="mobile-nav-close"
            id="mobile-nav-close"
            type="button"
            aria-label="Close menu"
          >
            <svg
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              aria-hidden="true"
            >
              <path
                d="M6 6l12 12M18 6 6 18"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
              />
            </svg>
          </button>
        </div>
        <div class="mobile-nav-body">
          <nav class="mobile-nav-links" aria-label="Mobile navigation">
            <a href="#approach"><small>01</small>Our approach</a>
            <a href="#story"><small>02</small>Our story</a>
            <a href="#how-it-works"><small>03</small>How it works</a>
            <a href="#opportunity"><small>04</small>Growth opportunity</a>
            <a href="#faq"><small>05</small>FAQ</a>
          </nav>
          <div class="mobile-nav-foot">
            <a class="mobile-nav-mail" href="mailto:contact@altasfarm.life"
              >Email the team</a
            >
            <a class="mobile-nav-login" href="<?= e($base) ?>/?page=login"
              >Member login</a
            >
            <a class="button button-gold" href="<?= e($base) ?>/?page=register"
              >Join free — it costs nothing</a
            >
            <p class="mobile-nav-note">
              Registration is free. There are no paid packages or tiers.
            </p>
          </div>
        </div>
      </div>

      <main id="main">
        <!-- ============================== HERO ============================== -->
        <section class="hero" id="home" aria-labelledby="hero-title">
          <div class="container hero-grid">
            <div class="hero-content">
              <p class="eyebrow">Wellness · Community · Shared growth</p>
              <h1 id="hero-title">
                <span class="line"
                  ><span class="line-inner">Grow something</span></span
                >
                <span class="line"
                  ><span class="line-inner"
                    >good. <em>For life.</em></span
                  ></span
                >
              </h1>
              <svg class="flourish" viewBox="0 0 212 16" aria-hidden="true">
                <path d="M3 11 C 60 3 148 3 209 9" />
                <path class="f2" d="M42 14 C 90 9 138 8 176 11" />
              </svg>
              <p class="hero-copy">
                AltasFarm Livelihood is a free-to-join community built around
                wellness products, genuine customer relationships and room to
                grow — as a person and, if you choose, as a leader. Registering
                costs nothing, and the rules are written down before you commit
                to anything.
              </p>
              <div class="hero-actions">
                <a class="button button-primary" href="<?= e($base) ?>/?page=register"
                  >Join free
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M5 12h14M12 5l7 7-7 7"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </a>
                <a class="button button-ghost" href="#how-it-works"
                  >See how it works</a
                >
              </div>
              <p class="hero-note">
                <span class="check-circle" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none">
                    <path
                      d="M20 6 9 17l-5-5"
                      stroke="currentColor"
                      stroke-width="3"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </span>
                Free to register. There are no paid tiers.
              </p>
              <div class="hero-stats">
                <div class="hero-stat">
                  <strong
                    ><span data-count="100" data-suffix="%">0%</span></strong
                  ><span>free to register and explore</span>
                </div>
                <div class="hero-stat">
                  <strong><span data-count="4">0</span></strong
                  ><span>paths to grow, from products to profit share</span>
                </div>
                <div class="hero-stat">
                  <strong>0</strong
                  ><span>paid entry tiers — today or ever</span>
                </div>
              </div>
            </div>

            <div class="hero-visual-wrap">
              <div class="hero-visual" id="hero-visual">
                <svg class="scene" viewBox="0 0 600 660" aria-hidden="true">
                  <defs>
                    <linearGradient id="skyG" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0" stop-color="#f4f2e4" />
                      <stop offset="1" stop-color="#e0e8d1" />
                    </linearGradient>
                    <linearGradient id="fieldG" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0" stop-color="#8cad77" />
                      <stop offset="1" stop-color="#73965f" />
                    </linearGradient>
                  </defs>
                  <rect width="600" height="660" fill="url(#skyG)" />

                  <!-- SVG paints in document order: the sun sits at the back, clouds over it, birds over the clouds. -->
                  <g class="layer" data-depth=".14">
                    <g
                      class="sun-rays"
                      stroke="#c9a35c"
                      stroke-width="2"
                      stroke-linecap="round"
                      opacity=".6"
                    >
                      <path d="M462 112V98" />
                      <path d="M502 128l10-10" />
                      <path d="M422 128l-10-10" />
                      <path d="M518 168h14" />
                      <path d="M406 168h-14" />
                      <path d="M502 208l10 10" />
                      <path d="M422 208l-10 10" />
                    </g>
                    <circle cx="462" cy="168" r="44" fill="#e9d3a0" />
                  </g>

                  <g class="layer" data-depth=".06">
                    <g class="cloud-a" fill="#fffef7" opacity=".8">
                      <ellipse cx="150" cy="120" rx="46" ry="15" />
                      <ellipse cx="186" cy="108" rx="30" ry="12" />
                      <ellipse cx="118" cy="112" rx="26" ry="11" />
                    </g>
                    <g class="cloud-b" fill="#fffef7" opacity=".55">
                      <ellipse cx="415" cy="64" rx="38" ry="12" />
                      <ellipse cx="446" cy="55" rx="24" ry="10" />
                    </g>
                  </g>

                  <g class="layer" data-depth=".1">
                    <g
                      class="birds"
                      stroke="#3c5347"
                      stroke-width="2"
                      fill="none"
                      stroke-linecap="round"
                      opacity=".7"
                    >
                      <path d="M120 182q7-8 14 0q7-8 14 0" />
                      <path d="M178 158q6-7 12 0q6-7 12 0" />
                    </g>
                  </g>

                  <g class="layer" data-depth=".18">
                    <path
                      d="M0 300 C 90 250 210 246 300 282 C 380 314 470 306 600 258 L600 660 L0 660Z"
                      fill="#d9e4c6"
                    />
                  </g>
                  <g class="layer" data-depth=".26">
                    <path
                      d="M0 380 C 120 330 260 352 360 388 C 450 420 540 400 600 372 L600 660 L0 660Z"
                      fill="#bfd2ab"
                    />
                  </g>
                  <g class="layer" data-depth=".34">
                    <path
                      d="M0 460 C 100 414 250 440 340 470 C 430 500 520 480 600 448 L600 660 L0 660Z"
                      fill="#a3bd8c"
                    />
                    <g fill="#79a065">
                      <circle cx="70" cy="452" r="12" />
                      <circle cx="95" cy="458" r="9" />
                      <circle cx="520" cy="446" r="11" />
                      <circle cx="545" cy="452" r="8" />
                    </g>
                    <g fill="#4f7350">
                      <rect x="67" y="458" width="4" height="10" rx="2" />
                      <rect x="93" y="463" width="4" height="9" rx="2" />
                      <rect x="518" y="452" width="4" height="10" rx="2" />
                      <rect x="543" y="457" width="4" height="8" rx="2" />
                    </g>
                  </g>

                  <g class="layer" data-depth=".5">
                    <path
                      d="M0 545 C 150 505 430 505 600 548 L600 660 L0 660Z"
                      fill="url(#fieldG)"
                    />
                    <g
                      stroke="#6f8f5c"
                      stroke-width="3"
                      stroke-linecap="round"
                      fill="none"
                      opacity=".75"
                    >
                      <path
                        d="M22 578 C 190 556 410 556 578 580"
                        stroke-dasharray="1 20"
                      />
                      <path
                        d="M8 608 C 190 586 410 586 592 610"
                        stroke-dasharray="1 26"
                      />
                      <path
                        d="M0 638 C 190 618 410 618 600 640"
                        stroke-dasharray="1 32"
                      />
                    </g>
                  </g>

                  <!-- Chickens: free-roaming flock (see script at the end of the page) -->
                  <g class="layer" data-depth=".5">
                    <g class="chickens">
                      <g class="chicken hen" data-x="146" data-y="492" data-dir="1" transform="translate(146 492) scale(0.824 0.824)">
                        <ellipse cx="3" cy=".6" rx="15" ry="2.4" fill="#44663f" opacity=".28" />
                        <g class="leg-a" stroke="#d29a3f" stroke-width="1.8" stroke-linecap="round" fill="none"><path d="M0 -7V-.4M-3.2 0H1.6" /></g>
                        <g class="leg-b" stroke="#d29a3f" stroke-width="1.8" stroke-linecap="round" fill="none"><path d="M6 -7V-.4M2.8 0H7.6" /></g>
                        <g class="hen-body">
                          <path d="M-10 -18C-17 -19-21 -28-17 -34C-14 -29-10 -26-5 -24Z" fill="#7a4426" />
                          <path d="M-13 -17C-13 -9-6 -5 3 -5C11 -5 16 -10 15 -17C14 -23 8 -26 1 -25C-6 -24-13 -23-13 -17Z" fill="#bf7a47" />
                          <path d="M-7 -18C-5 -11 4 -9 9 -14C6 -21-3 -22-7 -18Z" fill="#9c5a30" />
                          <g class="hen-neck">
                            <path d="M9 -18C10.5 -23 11.8 -26.5 12.5 -29.5" stroke="#bf7a47" stroke-width="7" stroke-linecap="round" fill="none" />
                            <circle cx="13.2" cy="-32" r="4.8" fill="#bf7a47" />
                            <path d="M10.8 -35.6C10.6 -39.6 13.2 -40.2 13.6 -37.8C14.5 -40.8 17.4 -39.6 15.8 -36.4Z" fill="#c8553d" />
                            <path d="M17.6 -33.2L22.8 -31.6L17.7 -30.2Z" fill="#e0a73c" />
                            <circle cx="17" cy="-28.4" r="1.7" fill="#c8553d" />
                            <circle cx="15" cy="-32.9" r="1" fill="#223028" />
                          </g>
                        </g>
                      </g>
                      <g class="chicken hen" data-x="456" data-y="497" data-dir="-1" transform="translate(456 497) scale(-0.835 0.835)">
                        <ellipse cx="3" cy=".6" rx="15" ry="2.4" fill="#44663f" opacity=".28" />
                        <g class="leg-a" stroke="#d29a3f" stroke-width="1.8" stroke-linecap="round" fill="none"><path d="M0 -7V-.4M-3.2 0H1.6" /></g>
                        <g class="leg-b" stroke="#d29a3f" stroke-width="1.8" stroke-linecap="round" fill="none"><path d="M6 -7V-.4M2.8 0H7.6" /></g>
                        <g class="hen-body">
                          <path d="M-10 -18C-17 -19-21 -28-17 -34C-14 -29-10 -26-5 -24Z" fill="#cbbf9a" />
                          <path d="M-13 -17C-13 -9-6 -5 3 -5C11 -5 16 -10 15 -17C14 -23 8 -26 1 -25C-6 -24-13 -23-13 -17Z" fill="#f7f2e0" />
                          <path d="M-7 -18C-5 -11 4 -9 9 -14C6 -21-3 -22-7 -18Z" fill="#e2d8b8" />
                          <g class="hen-neck">
                            <path d="M9 -18C10.5 -23 11.8 -26.5 12.5 -29.5" stroke="#f7f2e0" stroke-width="7" stroke-linecap="round" fill="none" />
                            <circle cx="13.2" cy="-32" r="4.8" fill="#f7f2e0" />
                            <path d="M10.8 -35.6C10.6 -39.6 13.2 -40.2 13.6 -37.8C14.5 -40.8 17.4 -39.6 15.8 -36.4Z" fill="#c8553d" />
                            <path d="M17.6 -33.2L22.8 -31.6L17.7 -30.2Z" fill="#e0a73c" />
                            <circle cx="17" cy="-28.4" r="1.7" fill="#c8553d" />
                            <circle cx="15" cy="-32.9" r="1" fill="#223028" />
                          </g>
                        </g>
                      </g>
                      <g class="chicken chick" data-x="196" data-y="498" data-dir="1" transform="translate(196 498) scale(1.054 1.054)">
                        <ellipse cx="2" cy=".5" rx="9" ry="1.8" fill="#44663f" opacity=".28" />
                        <g class="leg-a" stroke="#d98f2f" stroke-width="1.4" stroke-linecap="round" fill="none"><path d="M-1.5 -3.6V-.3M-3.4 0H-.2" /></g>
                        <g class="leg-b" stroke="#d98f2f" stroke-width="1.4" stroke-linecap="round" fill="none"><path d="M2.5 -3.6V-.3M.6 0H3.8" /></g>
                        <g class="chick-body"><g transform="translate(0 -1.6)">
                          <path d="M-5.5 -11.5C-8 -12-10 -14-9.5 -16C-7 -14.8-5 -14-3.5 -13.5Z" fill="#e4b94b" />
                          <circle cx="0" cy="-8" r="7" fill="#f3d56f" />
                          <path d="M-4 -9C-2 -5 3 -4.5 5 -7.5C2.5 -11-2 -11.5-4 -9Z" fill="#e4b94b" />
                          <circle cx="7" cy="-14" r="4.6" fill="#f3d56f" />
                          <path d="M11.2 -14.8L15 -13.6L11.3 -12.6Z" fill="#e08f2f" />
                          <circle cx="8.7" cy="-14.8" r=".85" fill="#223028" />
                        </g></g>
                      </g>
                      <g class="chicken hen" data-x="84" data-y="500" data-dir="1" transform="translate(84 500) scale(0.841 0.841)">
                        <ellipse cx="3" cy=".6" rx="15" ry="2.4" fill="#44663f" opacity=".28" />
                        <g class="leg-a" stroke="#d29a3f" stroke-width="1.8" stroke-linecap="round" fill="none"><path d="M0 -7V-.4M-3.2 0H1.6" /></g>
                        <g class="leg-b" stroke="#d29a3f" stroke-width="1.8" stroke-linecap="round" fill="none"><path d="M6 -7V-.4M2.8 0H7.6" /></g>
                        <g class="hen-body">
                          <path d="M-10 -18C-17 -19-21 -28-17 -34C-14 -29-10 -26-5 -24Z" fill="#4d3926" />
                          <path d="M-13 -17C-13 -9-6 -5 3 -5C11 -5 16 -10 15 -17C14 -23 8 -26 1 -25C-6 -24-13 -23-13 -17Z" fill="#9a7b5c" />
                          <path d="M-7 -18C-5 -11 4 -9 9 -14C6 -21-3 -22-7 -18Z" fill="#76583d" />
                          <g class="hen-neck">
                            <path d="M9 -18C10.5 -23 11.8 -26.5 12.5 -29.5" stroke="#9a7b5c" stroke-width="7" stroke-linecap="round" fill="none" />
                            <circle cx="13.2" cy="-32" r="4.8" fill="#9a7b5c" />
                            <path d="M10.8 -35.6C10.6 -39.6 13.2 -40.2 13.6 -37.8C14.5 -40.8 17.4 -39.6 15.8 -36.4Z" fill="#c8553d" />
                            <path d="M17.6 -33.2L22.8 -31.6L17.7 -30.2Z" fill="#e0a73c" />
                            <circle cx="17" cy="-28.4" r="1.7" fill="#c8553d" />
                            <circle cx="15" cy="-32.9" r="1" fill="#223028" />
                          </g>
                        </g>
                      </g>
                      <g class="chicken chick" data-x="503" data-y="503" data-dir="-1" transform="translate(503 503) scale(-1.067 1.067)">
                        <ellipse cx="2" cy=".5" rx="9" ry="1.8" fill="#44663f" opacity=".28" />
                        <g class="leg-a" stroke="#d98f2f" stroke-width="1.4" stroke-linecap="round" fill="none"><path d="M-1.5 -3.6V-.3M-3.4 0H-.2" /></g>
                        <g class="leg-b" stroke="#d98f2f" stroke-width="1.4" stroke-linecap="round" fill="none"><path d="M2.5 -3.6V-.3M.6 0H3.8" /></g>
                        <g class="chick-body"><g transform="translate(0 -1.6)">
                          <path d="M-5.5 -11.5C-8 -12-10 -14-9.5 -16C-7 -14.8-5 -14-3.5 -13.5Z" fill="#e4b94b" />
                          <circle cx="0" cy="-8" r="7" fill="#f3d56f" />
                          <path d="M-4 -9C-2 -5 3 -4.5 5 -7.5C2.5 -11-2 -11.5-4 -9Z" fill="#e4b94b" />
                          <circle cx="7" cy="-14" r="4.6" fill="#f3d56f" />
                          <path d="M11.2 -14.8L15 -13.6L11.3 -12.6Z" fill="#e08f2f" />
                          <circle cx="8.7" cy="-14.8" r=".85" fill="#223028" />
                        </g></g>
                      </g>
                      <g class="chicken chick" data-x="404" data-y="506" data-dir="-1" transform="translate(404 506) scale(-1.075 1.075)">
                        <ellipse cx="2" cy=".5" rx="9" ry="1.8" fill="#44663f" opacity=".28" />
                        <g class="leg-a" stroke="#d98f2f" stroke-width="1.4" stroke-linecap="round" fill="none"><path d="M-1.5 -3.6V-.3M-3.4 0H-.2" /></g>
                        <g class="leg-b" stroke="#d98f2f" stroke-width="1.4" stroke-linecap="round" fill="none"><path d="M2.5 -3.6V-.3M.6 0H3.8" /></g>
                        <g class="chick-body"><g transform="translate(0 -1.6)">
                          <path d="M-5.5 -11.5C-8 -12-10 -14-9.5 -16C-7 -14.8-5 -14-3.5 -13.5Z" fill="#e8cb70" />
                          <circle cx="0" cy="-8" r="7" fill="#f6e39a" />
                          <path d="M-4 -9C-2 -5 3 -4.5 5 -7.5C2.5 -11-2 -11.5-4 -9Z" fill="#e8cb70" />
                          <circle cx="7" cy="-14" r="4.6" fill="#f6e39a" />
                          <path d="M11.2 -14.8L15 -13.6L11.3 -12.6Z" fill="#e08f2f" />
                          <circle cx="8.7" cy="-14.8" r=".85" fill="#223028" />
                        </g></g>
                      </g>
                    </g>
                  </g>

                  <g class="layer" data-depth=".85">
                    <g class="sprout">
                      <path
                        d="M300 660 C 300 600 298 560 300 516"
                        stroke="#2f5b3f"
                        stroke-width="9"
                        fill="none"
                        stroke-linecap="round"
                      />
                      <path
                        d="M300 560 C 300 520 268 496 228 496 C 228 536 260 560 300 560Z"
                        fill="#3c6b4b"
                      />
                      <path
                        d="M300 540 C 300 500 330 476 368 476 C 368 514 338 540 300 540Z"
                        fill="#527f52"
                      />
                      <path
                        d="M292 546 C 272 528 254 516 238 508"
                        stroke="#2f5b3f"
                        stroke-width="2"
                        fill="none"
                        opacity=".5"
                      />
                      <path
                        d="M308 528 C 326 512 344 500 360 492"
                        stroke="#2f5b3f"
                        stroke-width="2"
                        fill="none"
                        opacity=".5"
                      />
                      <path
                        d="M300 520 C 300 505 300 498 300 494"
                        stroke="#2f5b3f"
                        stroke-width="6"
                        stroke-linecap="round"
                      />
                      <!-- Sunflower petals: static shapes, animated by the petal script at the end of the page -->
                      <g class="petals">
                        <g class="petal" data-a="3.2" transform="rotate(3.2 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#f4d772" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="28.91" transform="rotate(28.91 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#e5bb4f" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="54.63" transform="rotate(54.63 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#f4d772" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="80.34" transform="rotate(80.34 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#e5bb4f" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="106.06" transform="rotate(106.06 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#f4d772" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="131.77" transform="rotate(131.77 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#e5bb4f" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="157.49" transform="rotate(157.49 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#f4d772" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="183.2" transform="rotate(183.2 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#e5bb4f" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="208.91" transform="rotate(208.91 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#f4d772" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="234.63" transform="rotate(234.63 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#e5bb4f" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="260.34" transform="rotate(260.34 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#f4d772" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="286.06" transform="rotate(286.06 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#e5bb4f" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="311.77" transform="rotate(311.77 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#f4d772" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                        <g class="petal" data-a="337.49" transform="rotate(337.49 300 484)"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#e5bb4f" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                      </g>
                      <g class="petal-flight" opacity="0"><path d="M300 475C303.3 473.7 304.6 469.2 304.2 464.4C303.9 460.9 302.3 456.4 300 452.8C297.7 456.4 296.1 460.9 295.8 464.4C295.4 469.2 296.7 473.7 300 475Z" fill="#e5bb4f" /><path d="M300 472.6V457.2" stroke="#d9a93f" stroke-width=".9" stroke-linecap="round" opacity=".45" /></g>
                      <circle cx="300" cy="484" r="13" fill="#e4c98a" />
                      <circle cx="300" cy="484" r="5" fill="#7d5e22" />
                    </g>
                  </g>
                </svg>

                <div class="seeds" aria-hidden="true">
                  <i style="--i: 0"></i><i style="--i: 1"></i
                  ><i style="--i: 2"></i><i style="--i: 3"></i
                  ><i style="--i: 4"></i><i style="--i: 5"></i
                  ><i style="--i: 6"></i><i style="--i: 7"></i
                  ><i style="--i: 8"></i><i style="--i: 9"></i
                  ><i style="--i: 10"></i><i style="--i: 11"></i
                  ><i style="--i: 12"></i><i style="--i: 13"></i>
                </div>

                <span class="art-kicker"
                  >Plate № 01 — Where good things grow</span
                >

                <div class="float-card one">
                  <span class="float-icon" aria-hidden="true">
                    <svg
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="1.7"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path d="M7 20h10" />
                      <path d="M10 20c5.5-2.5.8-6.4 3-10" />
                      <path
                        d="M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8z"
                      />
                      <path
                        d="M14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z"
                      />
                    </svg>
                  </span>
                  <span
                    ><strong>Free membership</strong
                    ><small>No fees and no paid tiers</small></span
                  >
                </div>
                <div class="float-card two">
                  <span class="float-icon" aria-hidden="true">
                    <svg
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="1.7"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path
                        d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                      />
                      <path
                        d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"
                      />
                    </svg>
                  </span>
                  <span
                    ><strong>Wellness first</strong
                    ><small>Products we’d use at home</small></span
                  >
                </div>
                <div class="float-card three">
                  <span class="float-icon" aria-hidden="true">
                    <svg
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="1.7"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path d="M22 7 13.5 15.5 8.5 10.5 2 17" />
                      <path d="M16 7h6v6" />
                    </svg>
                  </span>
                  <span
                    ><strong>Grow with guidance</strong
                    ><small>Mentors, training, clear next steps</small></span
                  >
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- ============================== MARQUEE ============================== -->
        <div class="marquee" aria-hidden="true">
          <div class="marquee-track">
            <div class="marquee-group">
              <span class="marquee-item"
                >Free to join
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >Wellness first
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >People over pressure
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >Growth with guidance
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >Rooted in trust
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >Shared success
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
            </div>
            <div class="marquee-group">
              <span class="marquee-item"
                >Free to join
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >Wellness first
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >People over pressure
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >Growth with guidance
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >Rooted in trust
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
              <span class="marquee-item"
                >Shared success
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                  /></svg
              ></span>
            </div>
          </div>
        </div>

        <!-- ============================== PILLARS ============================== -->
        <section
          class="section-pad"
          id="approach"
          aria-labelledby="pillars-title"
        >
          <div class="container pillars-layout">
            <div class="pillars-intro" data-reveal>
              <p class="eyebrow">Our approach</p>
              <h2 id="pillars-title">Four roots. One tree.</h2>
              <p>
                Everything we do grows from the same soil — honest products,
                honest relationships and honest numbers. No pageantry, no
                packages: just the four roots that keep a community standing.
              </p>
              <a class="text-link" href="#how-it-works"
                >See how the program works
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path
                    d="M5 12h14M12 5l7 7-7 7"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </a>
            </div>
            <div class="pillar-list">
              <div class="pillar-row" data-reveal>
                <span class="pillar-num" aria-hidden="true">01</span>
                <div>
                  <h3>Real products</h3>
                  <p>
                    Wellness and everyday essentials — herbal, organic and
                    practical lines — that earn repeat customers on merit. If we
                    wouldn’t use it at home, it doesn’t carry our name.
                  </p>
                </div>
                <span class="pillar-icon" aria-hidden="true">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path
                      d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                    />
                    <path
                      d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"
                    />
                  </svg>
                </span>
              </div>
              <div class="pillar-row" data-reveal style="--d: 0.08s">
                <span class="pillar-num" aria-hidden="true">02</span>
                <div>
                  <h3>Real relationships</h3>
                  <p>
                    We grow through genuine customer care, never pressure. Trust
                    is the product behind every product — it’s why customers
                    come back, and why members stay.
                  </p>
                </div>
                <span class="pillar-icon" aria-hidden="true">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                  </svg>
                </span>
              </div>
              <div class="pillar-row" data-reveal style="--d: 0.16s">
                <span class="pillar-num" aria-hidden="true">03</span>
                <div>
                  <h3>Real guidance</h3>
                  <p>
                    Nobody walks alone here. New members learn beside
                    experienced leaders, with mentorship, training and a clear
                    path from first question to confident contributor.
                  </p>
                </div>
                <span class="pillar-icon" aria-hidden="true">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <circle cx="12" cy="12" r="10" />
                    <path
                      d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36 6.36-2.12z"
                    />
                  </svg>
                </span>
              </div>
              <div class="pillar-row" data-reveal style="--d: 0.24s">
                <span class="pillar-num" aria-hidden="true">04</span>
                <div>
                  <h3>Real sharing</h3>
                  <p>
                    Qualified ranks may share in a designated portion of actual
                    net profit. Real business, published rules — and no
                    guarantees dressed up as certainties.
                  </p>
                </div>
                <span class="pillar-icon" aria-hidden="true">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path
                      d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"
                    />
                    <path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z" />
                    <path d="M7 21h10" />
                    <path d="M12 3v18" />
                    <path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2" />
                  </svg>
                </span>
              </div>
            </div>
          </div>
        </section>

        <!-- ============================== MANIFESTO ============================== -->
        <section class="manifesto" aria-label="Our promise">
          <div class="container">
            <p class="eyebrow on-dark" data-reveal>Our promise</p>
            <p class="manifesto-text" id="manifesto-text">
              We believe wellness is a practice, not a purchase. That growth is
              shared, not promised. And that a community rooted in honest
              products, genuine relationships and patient work can become a real
              livelihood — <em>for life.</em>
            </p>
            <p class="manifesto-sign" data-reveal>The AltasFarm principle</p>
          </div>
        </section>

        <!-- ============================== STORY ============================== -->
        <section class="story-band" id="story" aria-labelledby="story-title">
          <div class="container">
            <div class="story-card" data-reveal>
              <div class="story-art">
                <svg
                  class="rings-svg"
                  viewBox="0 0 500 500"
                  data-reveal
                  aria-hidden="true"
                >
                  <circle
                    class="ring r1"
                    cx="250"
                    cy="250"
                    r="70"
                    pathLength="100"
                  />
                  <circle
                    class="ring r2"
                    cx="250"
                    cy="250"
                    r="110"
                    pathLength="100"
                  />
                  <circle
                    class="ring r3"
                    cx="250"
                    cy="250"
                    r="150"
                    pathLength="100"
                  />
                  <circle
                    class="ring r4"
                    cx="250"
                    cy="250"
                    r="190"
                    pathLength="100"
                  />
                  <circle
                    class="ring-orbit"
                    cx="250"
                    cy="250"
                    r="230"
                    pathLength="100"
                  />
                  <g class="ring-center">
                    <g
                      transform="translate(250 258) scale(2.35) translate(-32 -32)"
                    >
                      <defs>
                        <mask
                          id="leaf-cut"
                          maskUnits="userSpaceOnUse"
                          x="0"
                          y="0"
                          width="64"
                          height="64"
                        >
                          <rect width="64" height="64" fill="#fff" />
                          <path
                            transform="translate(21.99 23.98) scale(0.82)"
                            d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                            fill="#000"
                            stroke="#000"
                            stroke-width="4.1"
                            stroke-linejoin="round"
                          />
                        </mask>
                      </defs>
                      <g mask="url(#leaf-cut)">
                        <path
                          d="M21 46.5 30.6 19.2"
                          stroke="#f7f6ee"
                          stroke-width="3.4"
                          stroke-linecap="round"
                        />
                        <path
                          d="M43 46.5 33.4 19.2"
                          stroke="#f7f6ee"
                          stroke-width="3.4"
                          stroke-linecap="round"
                        />
                      </g>
                      <g
                        transform="translate(21.99 23.98) scale(0.82)"
                        fill="none"
                        stroke="#9fb98e"
                        stroke-width="2.4"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      >
                        <path
                          d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                          fill="none"
                        />
                        <path
                          d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"
                        />
                      </g>
                      <circle cx="32" cy="15.8" r="4.15" fill="#e4c98a" />
                      <circle cx="32" cy="15.8" r="1.7" fill="#7d5e22" />
                      <path
                        d="M18.5 47.2h7"
                        stroke="#f7f6ee"
                        stroke-width="2.6"
                        stroke-linecap="round"
                      />
                      <path
                        d="M38.5 47.2h7"
                        stroke="#f7f6ee"
                        stroke-width="2.6"
                        stroke-linecap="round"
                      />
                    </g>
                  </g>
                </svg>
                <span class="art-caption"
                  >Fig. 02 — One ring for every season</span
                >
              </div>
              <div class="story-copy">
                <p class="eyebrow">Our story</p>
                <h2 id="story-title">Every harvest starts as a single seed.</h2>
                <p>
                  AltasFarm Livelihood began with a simple observation: people
                  stay when products are honest and community is real. So we
                  built both — a free-to-join program where nobody pays to
                  belong, members explore at their own pace, and leaders are
                  grown rather than appointed.
                </p>
                <p>
                  Season by season, ring by ring, the community deepens —
                  through products people actually use, relationships that
                  actually last, and a growth path that rewards patience over
                  pressure.
                </p>
                <a class="text-link" href="#how-it-works"
                  >Walk the path with us
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M5 12h14M12 5l7 7-7 7"
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </a>
              </div>
            </div>
          </div>
        </section>

        <!-- ============================== HOW IT WORKS ============================== -->
        <section
          class="steps-section section-pad"
          id="how-it-works"
          aria-labelledby="steps-title"
        >
          <div class="container">
            <div class="section-head" data-reveal>
              <p class="eyebrow">How it works</p>
              <h2 id="steps-title">From first hello to shared harvest.</h2>
              <p>
                Four honest stages, each at your own pace. No stage requires
                payment, and nothing is hidden behind a package.
              </p>
            </div>

            <div class="journey" id="journey">
              <span class="journey-track" aria-hidden="true"></span>
              <span
                class="journey-fill"
                id="journey-fill"
                aria-hidden="true"
              ></span>

              <article class="step" data-reveal>
                <span class="step-dot" aria-hidden="true"></span>
                <div class="step-card">
                  <span class="step-label">Step 01</span>
                  <h3>Register for free</h3>
                  <p>
                    Send your name and email through the form below and a team
                    member will walk you through registration. No fees, packages
                    or entry tiers are involved.
                  </p>
                </div>
              </article>

              <article class="step" data-reveal>
                <span class="step-dot" aria-hidden="true"></span>
                <div class="step-card">
                  <span class="step-label">Step 02</span>
                  <h3>Learn and explore</h3>
                  <p>
                    Get to know the products, the values and the people — at
                    your own pace. Ask anything; experienced leaders answer, not
                    scripts.
                  </p>
                </div>
              </article>

              <article class="step" data-reveal>
                <span class="step-dot" aria-hidden="true"></span>
                <div class="step-card">
                  <span class="step-label">Step 03</span>
                  <h3>Build relationships</h3>
                  <p>
                    Serve repeat customers with products that earn their trust.
                    If you choose, help new members find their footing the way
                    someone once found yours.
                  </p>
                </div>
              </article>

              <article class="step" data-reveal>
                <span class="step-dot" aria-hidden="true"></span>
                <div class="step-card">
                  <span class="step-label">Step 04</span>
                  <h3>Grow and share</h3>
                  <p>
                    Advance through leadership ranks as you qualify. Qualified
                    ranks may share in a designated portion of actual net profit
                    — earned, never promised.
                  </p>
                </div>
              </article>
            </div>
          </div>
        </section>

        <!-- ============================== OPPORTUNITY ============================== -->
        <section
          class="profit-section"
          id="opportunity"
          aria-labelledby="profit-title"
        >
          <div class="container profit-grid">
            <div data-reveal>
              <p class="eyebrow on-dark">The growth opportunity</p>
              <h2 id="profit-title">Growth that gives back.</h2>
              <p class="lead">
                Our livelihood program reserves a designated portion of actual
                net profit for qualified members. Not vague promises. Not
                “projected earnings.” Real business results — shared by the
                people who help create them, under rules published before you
                commit to anything.
              </p>
              <a class="button button-gold" href="#inquiry"
                >Ask about the program
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path
                    d="M5 12h14M12 5l7 7-7 7"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </a>
            </div>

            <div class="profit-panel" data-reveal style="--d: 0.12s">
              <span class="panel-label">The profit pool</span>
              <div class="pool" aria-hidden="true">
                <span class="orbit"></span>
                <span class="orbit o2"></span>
                <span class="pool-center">Share in<br />real profit</span>
              </div>
              <ul class="profit-points">
                <li>
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M20 6 9 17l-5-5"
                      stroke="currentColor"
                      stroke-width="2.2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    /></svg
                  ><span
                    >Tied to <strong>actual net profit</strong> — a designated
                    portion, not vague promises.</span
                  >
                </li>
                <li>
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M20 6 9 17l-5-5"
                      stroke="currentColor"
                      stroke-width="2.2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    /></svg
                  ><span
                    >Ranks are
                    <strong>earned through real, qualifying activity</strong> —
                    never bought.</span
                  >
                </li>
                <li>
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M20 6 9 17l-5-5"
                      stroke="currentColor"
                      stroke-width="2.2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    /></svg
                  ><span
                    >Rules and requirements are
                    <strong>published and explained up front</strong>.</span
                  >
                </li>
                <li>
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      d="M20 6 9 17l-5-5"
                      stroke="currentColor"
                      stroke-width="2.2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    /></svg
                  ><span
                    >Distributions are <strong>never guaranteed</strong> — and
                    we say so plainly.</span
                  >
                </li>
              </ul>
              <p class="disclaimer">
                Profit sharing refers to participation in a designated portion
                of actual business net profit by qualified ranks, as described
                in the official program guide. Eligibility depends on qualifying
                activity, and no distribution is guaranteed. Nothing on this
                page constitutes a promise of income.
              </p>
            </div>
          </div>
        </section>

        <!-- ============================== FAQ ============================== -->
        <section class="section-pad" id="faq" aria-labelledby="faq-title">
          <div class="container faq-layout">
            <div class="section-head" data-reveal>
              <p class="eyebrow">Questions</p>
              <h2 id="faq-title">Honest answers, always.</h2>
              <p>
                Everything people ask before joining — answered plainly, because
                trust starts before registration. If yours isn’t here, ask us
                directly; we reply to every message.
              </p>
              <a class="text-link" href="#inquiry"
                >Ask us your question
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path
                    d="M5 12h14M12 5l7 7-7 7"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </a>
            </div>

            <div class="faq-list" data-reveal>
              <details open>
                <summary>
                  What is AltasFarm Livelihood?<span
                    class="faq-plus"
                    aria-hidden="true"
                  ></span>
                </summary>
                <p>
                  AltasFarm Livelihood is a community built around wellness and
                  everyday products. Members serve repeat customers, support one
                  another, and may advance through leadership ranks as they
                  qualify.
                </p>
              </details>
              <details>
                <summary>
                  Is registration really free?<span
                    class="faq-plus"
                    aria-hidden="true"
                  ></span>
                </summary>
                <p>
                  Yes. Registering costs nothing, and there are no paid
                  membership packages or entry tiers. Any product purchase is
                  optional and separate from registration.
                </p>
              </details>
              <details>
                <summary>
                  What products will be available?<span
                    class="faq-plus"
                    aria-hidden="true"
                  ></span>
                </summary>
                <p>
                  The range may grow over time and may include wellness, herbal,
                  organic and other everyday products. Current descriptions,
                  prices and availability come from our team or the product
                  catalog.
                </p>
              </details>
              <details>
                <summary>
                  How do members take part in the livelihood opportunity?<span
                    class="faq-plus"
                    aria-hidden="true"
                  ></span>
                </summary>
                <p>
                  Participation centers on qualifying repeat-purchase activity
                  and building a team responsibly. The official program guide
                  sets out the requirements, qualification rules and any
                  compensation, so you can read them before you decide.
                </p>
              </details>
              <details>
                <summary>
                  What does profit sharing mean?<span
                    class="faq-plus"
                    aria-hidden="true"
                  ></span>
                </summary>
                <p>
                  Qualified ranks may be eligible to share in a designated
                  portion of the business’s actual net profit. Eligibility and
                  amounts follow the published policy, and no distribution is
                  guaranteed.
                </p>
              </details>
              <details>
                <summary>
                  Do I need sales or leadership experience?<span
                    class="faq-plus"
                    aria-hidden="true"
                  ></span>
                </summary>
                <p>
                  No. You can inquire or register with no experience. Start by
                  learning about the products and asking a team leader to
                  explain the program and your options.
                </p>
              </details>
              <details>
                <summary>
                  Will I earn just by inviting people to register?<span
                    class="faq-plus"
                    aria-hidden="true"
                  ></span>
                </summary>
                <p>
                  Not on its own. Registering people is not an automatic
                  entitlement to compensation — the program is built on real
                  product activity, repeat customers and responsible team
                  development.
                </p>
              </details>
            </div>
          </div>
        </section>

        <!-- ============================== CONTACT ==============================
       Pattern from reference: dark details box beside a bordered map
       frame, with a meta row + pill actions underneath the map.
       ============================================================ -->
        <section
          class="contact-section"
          id="inquiry"
          aria-labelledby="contact-title"
        >
          <div class="container">
            <div class="section-head" data-reveal>
              <p class="eyebrow">Visit · Write · Begin</p>
              <h2 id="contact-title">Come as you are. It’s free to begin.</h2>
              <p>
                One honest conversation is where every growth story starts.
                Reach us any way you like — we’re easy to talk to and hard to
                pressure.
              </p>
            </div>

            <div class="contact-grid">
              <!-- Details box -->
              <div class="contact-box" data-reveal>
                <h3>Talk to a real person.</h3>
                <p>
                  No bots, no scripts, no sales funnel. Ask anything about the
                  products, the program, or what “free” really means — someone
                  who knows will answer.
                </p>

                <div class="contact-details">
                  <div class="contact-detail">
                    <span class="cd-label">Visit</span>
                    <span class="cd-value"
                      >Rang-ay, Cabatuan<br />Isabela 3315, Philippines
                      <span class="cd-note" style="display: block"
                        >Meetup and pickup points are shared on inquiry.</span
                      >
                    </span>
                  </div>
                  <div class="contact-detail">
                    <span class="cd-label">Email</span>
                    <span class="cd-value"
                      ><a href="mailto:contact@altasfarm.life"
                        >contact@altasfarm.life</a
                      ></span
                    >
                  </div>
                  <!-- Remove this block if the livelihood program has its own numbers -->
                  <div class="contact-detail">
                    <span class="cd-label">Call or text</span>
                    <span class="cd-value"
                      ><a href="tel:+639060987081">+63 906 098 7081</a> ·
                      <a href="tel:+639996689052">+63 999 668 9052</a></span
                    >
                  </div>
                  <div class="contact-detail">
                    <span class="cd-label">Hours</span>
                    <span class="cd-value"
                      >Monday – Saturday · 9:00 – 18:00 (PHT)</span
                    >
                  </div>
                  <div class="contact-detail">
                    <span class="cd-label">Follow</span>
                    <span class="cd-value"
                      ><a
                        href="https://www.facebook.com/people/Altas-Farm/61595099030024/"
                        target="_blank"
                        rel="noopener"
                        >Facebook — AltasFarm</a
                      ></span
                    >
                  </div>
                </div>
              </div>

              <!-- Map column -->
              <div class="map-col" data-reveal style="--d: 0.08s">
                <div class="map-frame">
                  <iframe
                    src="https://www.google.com/maps?q=16.9266802,121.616111&z=13&output=embed"
                    loading="lazy"
                    title="Map — AltasFarm Livelihood, Rang-ay, Cabatuan, Isabela"
                    referrerpolicy="no-referrer-when-downgrade"
                    allowfullscreen
                  ></iframe>
                </div>
                <div class="map-meta">
                  <span>
                    <svg
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="1.8"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      aria-hidden="true"
                    >
                      <path
                        d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"
                      />
                      <circle cx="12" cy="10" r="3" />
                    </svg>
                    Rang-ay, Cabatuan, Isabela — Luzon, Philippines
                  </span>
                  <div class="map-actions">
                    <a
                      href="https://maps.app.goo.gl/3xmJeKfKTj1NyaJ29"
                      target="_blank"
                      rel="noopener"
                      >Get directions
                      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path
                          d="M7 17 17 7"
                          stroke="currentColor"
                          stroke-width="1.8"
                          stroke-linecap="round"
                        />
                        <path
                          d="M8 7h9v9"
                          stroke="currentColor"
                          stroke-width="1.8"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                    </a>
                    <a href="mailto:contact@altasfarm.life">Email us</a>
                  </div>
                </div>
              </div>
            </div>

            <!-- Inquiry form -->
            <div class="inquiry-form" data-reveal>
              <h3>Prefer to write?</h3>
              <p class="form-intro">
                Free to send, free to walk away. We only ask for what we need to
                reply.
              </p>

              <form
                id="inquiry-form"
                novalidate
                data-endpoint=""
                data-mailto="contact@altasfarm.life"
              >
                <div
                  style="position: absolute; left: -9999px"
                  aria-hidden="true"
                >
                  <label
                    >Leave this empty<input
                      name="website"
                      tabindex="-1"
                      autocomplete="off"
                  /></label>
                </div>
                <div class="field-row">
                  <div class="field">
                    <label for="f-name">Full name</label>
                    <input
                      id="f-name"
                      name="name"
                      type="text"
                      autocomplete="name"
                      placeholder="Your name"
                    />
                    <small class="field-error" aria-live="polite"></small>
                  </div>
                  <div class="field">
                    <label for="f-email">Email</label>
                    <input
                      id="f-email"
                      name="email"
                      type="email"
                      autocomplete="email"
                      placeholder="you@example.com"
                    />
                    <small class="field-error" aria-live="polite"></small>
                  </div>
                </div>
                <div class="field">
                  <label for="f-topic">I’m curious about</label>
                  <select id="f-topic" name="topic">
                    <option>Joining the community</option>
                    <option>The products</option>
                    <option>The growth program</option>
                    <option>Something else</option>
                  </select>
                </div>
                <div class="field">
                  <label for="f-message">Your question</label>
                  <textarea
                    id="f-message"
                    name="message"
                    placeholder="What would you like to know?"
                  ></textarea>
                  <small class="field-error" aria-live="polite"></small>
                </div>
                <button
                  class="button button-primary form-submit"
                  id="form-submit"
                  type="submit"
                >
                  <span class="btn-label">Send my question</span>
                  <svg
                    class="btn-send"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path d="m22 2-7 20-4-9-9-4Z" />
                    <path d="M22 2 11 13" />
                  </svg>
                  <span class="btn-spinner" aria-hidden="true"></span>
                </button>
                <p class="form-note">
                  We use your details only to reply to your question. Prefer
                  email? Write to
                  <a href="mailto:contact@altasfarm.life"
                    >contact@altasfarm.life</a
                  >.
                </p>
              </form>

              <div class="form-success" id="form-success" hidden>
                <span class="success-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none">
                    <path
                      d="M20 6 9 17l-5-5"
                      stroke="currentColor"
                      stroke-width="2.4"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </span>
                <strong id="success-title">Message received.</strong>
                <p id="success-text">
                  Thank you — a real person will read it and reply, usually
                  within two business days.
                </p>
                <button type="button" class="text-link" id="send-another">
                  Send another message
                </button>
              </div>
            </div>
          </div>
        </section>

        <!-- ============================== FINAL CTA ============================== -->
        <section class="final-cta" aria-label="Get started">
          <div class="container final-cta-inner" data-reveal>
            <div>
              <h2>Ready to grow something <em>good?</em></h2>
              <p>
                Registration is free. Look through the products, meet the
                community and decide at your own pace — no purchase is needed to
                register.
              </p>
            </div>
            <a class="button button-gold" href="<?= e($base) ?>/?page=register"
              >Join free
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path
                  d="M5 12h14M12 5l7 7-7 7"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </a>
          </div>
        </section>
      </main>

      <!-- ============================== FOOTER ============================== -->
      <footer class="site-footer">
        <div class="container">
          <div class="footer-top">
            <div class="footer-brand">
              <a
                class="brand"
                href="#home"
                aria-label="AltasFarm Livelihood — back to top"
              >
                <svg
                  class="brand-mark"
                  viewBox="0 0 64 64"
                  aria-hidden="true"
                  focusable="false"
                >
                  <rect width="64" height="64" rx="18" fill="#10281d" />
                  <rect
                    x="2"
                    y="2"
                    width="60"
                    height="60"
                    rx="16"
                    fill="none"
                    stroke="#c9a35c"
                    stroke-width="1.15"
                    opacity="0.62"
                  />
                  <path
                    d="M21 46.5 30.6 19.2"
                    stroke="#f7f6ee"
                    stroke-width="3.4"
                    stroke-linecap="round"
                  />
                  <path
                    d="M43 46.5 33.4 19.2"
                    stroke="#f7f6ee"
                    stroke-width="3.4"
                    stroke-linecap="round"
                  />
                  <g
                    transform="translate(21.99 23.98) scale(0.82)"
                    fill="none"
                    stroke="#9fb98e"
                    stroke-width="2.4"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path
                      d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
                      fill="#10281d"
                    />
                    <path
                      d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"
                    />
                  </g>
                  <circle cx="32" cy="15.8" r="4.15" fill="#e4c98a" />
                  <circle cx="32" cy="15.8" r="1.7" fill="#7d5e22" />
                  <path
                    d="M18.5 47.2h7"
                    stroke="#f7f6ee"
                    stroke-width="2.6"
                    stroke-linecap="round"
                  />
                  <path
                    d="M38.5 47.2h7"
                    stroke="#f7f6ee"
                    stroke-width="2.6"
                    stroke-linecap="round"
                  />
                </svg>
                <span>
                  <span class="brand-name">AltasFarm</span>
                  <span class="brand-sub">Livelihood</span>
                </span>
              </a>
              <p class="footer-summary">
                A free-to-join wellness and livelihood community — growing good
                products, good relationships and good people.
              </p>
              <div class="social-row">
                <a
                  class="social-btn"
                  href="https://www.facebook.com/people/Altas-Farm/61595099030024/"
                  target="_blank"
                  rel="noopener"
                  aria-label="AltasFarm on Facebook"
                >
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"
                    />
                  </svg>
                </a>
                <a
                  class="social-btn"
                  href="mailto:contact@altasfarm.life"
                  aria-label="Email AltasFarm Livelihood"
                >
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <rect width="20" height="16" x="2" y="4" rx="2" />
                    <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                  </svg>
                </a>
                <a
                  class="social-btn"
                  href="https://maps.app.goo.gl/3xmJeKfKTj1NyaJ29"
                  target="_blank"
                  rel="noopener"
                  aria-label="Find AltasFarm on the map"
                >
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                    <circle cx="12" cy="10" r="3" />
                  </svg>
                </a>
              </div>
            </div>

            <nav class="footer-col" aria-label="Explore">
              <strong>Explore</strong>
              <a href="#approach">Our approach</a>
              <a href="#story">Our story</a>
              <a href="#how-it-works">How it works</a>
              <a href="#opportunity">Growth opportunity</a>
              <a href="#faq">FAQ</a>
            </nav>

            <nav class="footer-col" aria-label="Get started">
              <strong>Begin</strong>
              <a href="#inquiry">Join free</a>
              <a href="#inquiry">Ask a question</a>
              <a href="mailto:contact@altasfarm.life">Email the team</a>
              <a
                href="https://www.facebook.com/people/Altas-Farm/61595099030024/"
                target="_blank"
                rel="noopener"
                >Facebook</a
              >
            </nav>

            <div class="footer-col footer-contact" aria-label="Contact details">
              <strong>Contact</strong>
              <address>
                <span class="fc-item">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                    <circle cx="12" cy="10" r="3" />
                  </svg>
                  <a
                    href="https://maps.app.goo.gl/3xmJeKfKTj1NyaJ29"
                    target="_blank"
                    rel="noopener"
                    >Rang-ay, Cabatuan<br />Isabela 3315, Philippines</a
                  >
                </span>
                <a href="mailto:contact@altasfarm.life">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <rect width="20" height="16" x="2" y="4" rx="2" />
                    <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                  </svg>
                  <span>contact@altasfarm.life</span>
                </a>
                <span class="fc-item">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path
                      d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.32 1.78.59 2.63a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.45-1.16a2 2 0 0 1 2.11-.45c.85.27 1.73.47 2.63.59A2 2 0 0 1 22 16.92z"
                    />
                  </svg>
                  <span
                    ><a href="tel:+639060987081">+63 906 098 7081</a><br /><a
                      href="tel:+639996689052"
                      >+63 999 668 9052</a
                    ></span
                  >
                </span>
                <span class="fc-item">
                  <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.7"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <circle cx="12" cy="12" r="10" />
                    <path d="M12 6v6l4 2" />
                  </svg>
                  <span>Mon – Sat · 9:00 – 18:00 (PHT)</span>
                </span>
              </address>
            </div>
          </div>

          <div class="footer-bottom">
            <span
              >© <span id="year">2026</span> AltasFarm Livelihood. All rights
              reserved.</span
            >
            <p class="footer-disclaimer">
              Membership is free. Product purchases are optional and separate
              from registration. Rank advancement depends on qualifying activity
              as described in the official program guide. Participation in
              profit sharing is not guaranteed, and no income is promised or
              implied.
            </p>
          </div>
        </div>
      </footer>

      <!-- Floating UI -->
      <div class="toast" id="toast" role="status" aria-live="polite">
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.7"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path
            d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"
          />
          <path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12" />
        </svg>
        <span class="toast-msg"></span>
      </div>

      <!-- Back to top — gold circle -->
      <button class="to-top" id="to-top" type="button" aria-label="Back to top">
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2.2"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
          focusable="false"
        >
          <path d="m5 12 7-7 7 7" />
          <path d="M12 19V5" />
        </svg>
      </button>

      <div class="mobile-cta">
        <a class="button button-primary" href="<?= e($base) ?>/?page=register">Join free</a>
        <a class="button button-ghost" href="#how-it-works">How it works</a>
      </div>
      <script>
        /* Show the sticky mobile CTA bar only once the hero buttons have scrolled out of view */
        (function () {
          var bar = document.querySelector(".mobile-cta"),
            hero = document.querySelector(".hero-actions");
          if (!bar || !hero || !("IntersectionObserver" in window)) return;
          new IntersectionObserver(
            function (e) {
              bar.classList.toggle("is-away", e[0].isIntersecting);
            },
            { threshold: 0.01 },
          ).observe(hero);
        })();
      </script>
    </div>

    <script>
      (() => {
        function useEffect(fn) {
          fn();
        }

        function useAltasMotion() {
          useEffect(() => {
            var _a;
            const doc = document;
            const $ = (s, c) => (c || doc).querySelector(s);
            const $$ = (s, c) => Array.from((c || doc).querySelectorAll(s));
            const reduced = window.matchMedia(
              "(prefers-reduced-motion: reduce)",
            ).matches;
            const vh = () => window.innerHeight;
            const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
            const cleanups = [];
            const header = $(".site-header");
            const onHeader = () =>
              header == null
                ? void 0
                : header.classList.toggle("is-scrolled", window.scrollY > 10);
            const toggle = $(".menu-toggle");
            const mobileNav = $("#mobile-nav");
            let mOpen = false;
            const setOpen = (open) => {
              mOpen = open;
              mobileNav == null
                ? void 0
                : mobileNav.classList.toggle("open", open);
              mobileNav == null
                ? void 0
                : mobileNav.setAttribute(
                    "aria-hidden",
                    open ? "false" : "true",
                  );
              doc.documentElement.classList.toggle("nav-locked", open);
              toggle == null
                ? void 0
                : toggle.setAttribute("aria-expanded", open ? "true" : "false");
            };
            const openMobileNav = () => {
              setOpen(true);
              const first = $(".mobile-nav-links a");
              first == null ? void 0 : first.focus();
            };
            const closeMobileNav = () => {
              setOpen(false);
              toggle == null ? void 0 : toggle.focus();
            };
            const onToggleClick = () =>
              mOpen ? closeMobileNav() : openMobileNav();
            const onCloseClick = () => closeMobileNav();
            const onNavKey = (e) => {
              if (e.key === "Escape" && mOpen) closeMobileNav();
            };
            const onNavResize = () => {
              if (mOpen && window.innerWidth > 760) closeMobileNav();
            };
            if (toggle && mobileNav) {
              toggle.addEventListener("click", onToggleClick);
              (_a = $("#mobile-nav-close")) == null
                ? void 0
                : _a.addEventListener("click", onCloseClick);
              const links = $$("a", mobileNav);
              links.forEach((a) => a.addEventListener("click", onCloseClick));
              doc.addEventListener("keydown", onNavKey);
              window.addEventListener("resize", onNavResize);
              cleanups.push(() => {
                var _a2;
                toggle.removeEventListener("click", onToggleClick);
                (_a2 = $("#mobile-nav-close")) == null
                  ? void 0
                  : _a2.removeEventListener("click", onCloseClick);
                links.forEach((a) =>
                  a.removeEventListener("click", onCloseClick),
                );
                doc.removeEventListener("keydown", onNavKey);
                window.removeEventListener("resize", onNavResize);
                setOpen(false);
              });
            }
            const revealIO = new IntersectionObserver(
              (entries) => {
                entries.forEach((en) => {
                  if (en.isIntersecting) {
                    en.target.classList.add("is-in");
                    revealIO.unobserve(en.target);
                  }
                });
              },
              { threshold: 0.12, rootMargin: "0px 0px -8% 0px" },
            );
            $$("[data-reveal]").forEach((el) => revealIO.observe(el));
            cleanups.push(() => revealIO.disconnect());
            const animateCount = (el) => {
              const target = parseFloat(el.getAttribute("data-count") || "0");
              const suffix = el.getAttribute("data-suffix") || "";
              if (reduced) {
                el.textContent = String(target) + suffix;
                return;
              }
              const dur = 1500;
              const t0 = performance.now();
              let raf = 0;
              const tick = (t) => {
                const p = clamp((t - t0) / dur, 0, 1);
                const e = 1 - Math.pow(1 - p, 4);
                el.textContent = String(Math.round(target * e)) + suffix;
                if (p < 1) raf = requestAnimationFrame(tick);
              };
              raf = requestAnimationFrame(tick);
              cleanups.push(() => cancelAnimationFrame(raf));
            };
            const countIO = new IntersectionObserver(
              (entries) => {
                entries.forEach((en) => {
                  if (en.isIntersecting) {
                    animateCount(en.target);
                    countIO.unobserve(en.target);
                  }
                });
              },
              { threshold: 0.6 },
            );
            $$("[data-count]").forEach((el) => countIO.observe(el));
            cleanups.push(() => countIO.disconnect());
            const navLinks = $$(".nav-links a[href^='#']:not(.button)");
            const sectionIO = new IntersectionObserver(
              (entries) => {
                entries.forEach((en) => {
                  if (!en.isIntersecting) return;
                  navLinks.forEach((a) => {
                    a.classList.toggle(
                      "is-active",
                      a.getAttribute("href") === "#" + en.target.id,
                    );
                  });
                });
              },
              { rootMargin: "-35% 0px -55% 0px" },
            );
            ["approach", "how-it-works", "opportunity", "faq"].forEach((id) => {
              const s = doc.getElementById(id);
              if (s) sectionIO.observe(s);
            });
            cleanups.push(() => sectionIO.disconnect());
            const manifesto = $("#manifesto-text");
            let mWords = [];
            if (manifesto && !manifesto.querySelector(".mw")) {
              const wrap = (node) => {
                Array.from(node.childNodes).forEach((ch) => {
                  if (ch.nodeType === 3) {
                    const frag = doc.createDocumentFragment();
                    (ch.textContent || "").split(/(\s+)/).forEach((tok) => {
                      if (!tok) return;
                      if (/^\s+$/.test(tok))
                        frag.appendChild(doc.createTextNode(tok));
                      else {
                        const s = doc.createElement("span");
                        s.className = "mw";
                        s.textContent = tok;
                        frag.appendChild(s);
                      }
                    });
                    node.replaceChild(frag, ch);
                  } else if (ch.nodeType === 1) wrap(ch);
                });
              };
              wrap(manifesto);
            }
            if (manifesto) mWords = $$(".mw", manifesto);
            const progress = $("#scroll-progress");
            const journeyFill = $("#journey-fill");
            const journey = $("#journey");
            const steps = $$(".step");
            const toTop = $("#to-top");
            let ticking = false;
            const onScroll = () => {
              if (ticking) return;
              ticking = true;
              requestAnimationFrame(() => {
                ticking = false;
                const h = doc.documentElement;
                const max = h.scrollHeight - h.clientHeight;
                if (progress)
                  progress.style.transform =
                    "scaleX(" + (max > 0 ? window.scrollY / max : 0) + ")";
                if (mWords.length && manifesto) {
                  const r = manifesto.getBoundingClientRect();
                  const start = vh() * 0.88;
                  const end = vh() * 0.38;
                  const p = clamp(
                    (start - r.top) / (start - (end - r.height)),
                    0,
                    1,
                  );
                  const n = Math.round(p * mWords.length);
                  mWords.forEach((w, i) => w.classList.toggle("is-on", i < n));
                }
                if (journeyFill && journey) {
                  const jr = journey.getBoundingClientRect();
                  const jp = clamp(
                    (vh() * 0.75 - jr.top) / (jr.height + vh() * 0.12),
                    0,
                    1,
                  );
                  journeyFill.style.transform = "scaleY(" + jp + ")";
                }
                steps.forEach((s) =>
                  s.classList.toggle(
                    "is-active",
                    s.getBoundingClientRect().top < vh() * 0.62,
                  ),
                );
                toTop == null
                  ? void 0
                  : toTop.classList.toggle("is-visible", window.scrollY > 600);
                onHeader();
              });
            };
            window.addEventListener("scroll", onScroll, { passive: true });
            window.addEventListener("resize", onScroll);
            onScroll();
            cleanups.push(() => {
              window.removeEventListener("scroll", onScroll);
              window.removeEventListener("resize", onScroll);
            });
            const visual = $("#hero-visual");
            if (
              visual &&
              !reduced &&
              window.matchMedia("(pointer:fine)").matches
            ) {
              const hero = $("#home");
              const layers = $$("[data-depth]", visual);
              let tx = 0;
              let ty = 0;
              let cx = 0;
              let cy = 0;
              let run = true;
              const onMove = (e) => {
                const pe = e;
                const r = visual.getBoundingClientRect();
                tx = clamp(((pe.clientX - r.left) / r.width - 0.5) * 2, -1, 1);
                ty = clamp(((pe.clientY - r.top) / r.height - 0.5) * 2, -1, 1);
              };
              const onLeave = () => {
                tx = 0;
                ty = 0;
              };
              hero == null
                ? void 0
                : hero.addEventListener("pointermove", onMove);
              hero == null
                ? void 0
                : hero.addEventListener("pointerleave", onLeave);
              const apply = () => {
                if (!run) return;
                cx += (tx - cx) * 0.07;
                cy += (ty - cy) * 0.07;
                layers.forEach((l) => {
                  const d = parseFloat(l.getAttribute("data-depth") || "0");
                  l.style.transform =
                    "translate(" +
                    (cx * 16 * d).toFixed(2) +
                    "px," +
                    (cy * 10 * d).toFixed(2) +
                    "px)";
                });
                visual.style.transform =
                  "perspective(900px) rotateX(" +
                  (-cy * 1.5).toFixed(3) +
                  "deg) rotateY(" +
                  (cx * 2.1).toFixed(3) +
                  "deg)";
                requestAnimationFrame(apply);
              };
              requestAnimationFrame(apply);
              cleanups.push(() => {
                run = false;
                hero == null
                  ? void 0
                  : hero.removeEventListener("pointermove", onMove);
                hero == null
                  ? void 0
                  : hero.removeEventListener("pointerleave", onLeave);
                visual.style.transform = "";
                layers.forEach((l) => {
                  l.style.transform = "";
                });
              });
            }
            const seedCanvas = $("#seeds-canvas");
            if (seedCanvas && !reduced) {
              const ctx = seedCanvas.getContext("2d");
              if (ctx) {
                let W = 0;
                let H = 0;
                let running = true;
                let alive = true;
                let t = 0;
                const mouse = { x: -999, y: -999 };
                const COLORS = [
                  "rgba(201,163,92,",
                  "rgba(233,238,222,",
                  "rgba(120,150,110,",
                ];
                let seeds = [];
                const resizeCanvas = () => {
                  const dpr = Math.min(window.devicePixelRatio || 1, 2);
                  W = seedCanvas.clientWidth;
                  H = seedCanvas.clientHeight;
                  seedCanvas.width = W * dpr;
                  seedCanvas.height = H * dpr;
                  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                };
                const makeSeed = (init) => ({
                  x: Math.random() * W,
                  y: init ? Math.random() * H : H + 12,
                  s: 0.15 + Math.random() * 0.35,
                  sz: 1.6 + Math.random() * 2.6,
                  a: 0.3 + Math.random() * 0.4,
                  f: 0.4 + Math.random() * 0.9,
                  o: Math.random() * Math.PI * 2,
                  r: Math.random() * Math.PI,
                  vr: (Math.random() - 0.5) * 0.01,
                  c: COLORS[(Math.random() * COLORS.length) | 0] || COLORS[0],
                });
                const initSeeds = () => {
                  resizeCanvas();
                  const n = Math.max(
                    18,
                    Math.min(40, Math.round((W * H) / 16e3)),
                  );
                  seeds = [];
                  for (let i = 0; i < n; i++) seeds.push(makeSeed(true));
                };
                const host = seedCanvas.parentElement;
                const onPtr = (e) => {
                  const pe = e;
                  const r = seedCanvas.getBoundingClientRect();
                  mouse.x = pe.clientX - r.left;
                  mouse.y = pe.clientY - r.top;
                };
                const onPtrLeave = () => {
                  mouse.x = -999;
                  mouse.y = -999;
                };
                host == null
                  ? void 0
                  : host.addEventListener("pointermove", onPtr);
                host == null
                  ? void 0
                  : host.addEventListener("pointerleave", onPtrLeave);
                const frame = () => {
                  if (!alive) return;
                  if (running && !doc.hidden) {
                    t += 0.016;
                    ctx.clearRect(0, 0, W, H);
                    for (let i = 0; i < seeds.length; i++) {
                      let p = seeds[i];
                      if (!p) continue;
                      p.y -= p.s;
                      p.x += Math.sin(t * p.f + p.o) * 0.35;
                      p.r += p.vr;
                      const dx = p.x - mouse.x;
                      const dy = p.y - mouse.y;
                      const d2 = dx * dx + dy * dy;
                      if (d2 < 8100 && d2 > 0.01) {
                        const d = Math.sqrt(d2);
                        const f = ((90 - d) / 90) * 1.4;
                        p.x += (dx / d) * f;
                        p.y += (dy / d) * f;
                      }
                      if (p.y < -14) {
                        seeds[i] = makeSeed(false);
                        p = seeds[i];
                      }
                      ctx.save();
                      ctx.translate(p.x, p.y);
                      ctx.rotate(p.r);
                      ctx.beginPath();
                      ctx.ellipse(0, 0, p.sz * 2, p.sz, 0, 0, Math.PI * 2);
                      ctx.fillStyle = p.c + p.a + ")";
                      ctx.fill();
                      ctx.restore();
                    }
                  }
                  requestAnimationFrame(frame);
                };
                initSeeds();
                requestAnimationFrame(frame);
                window.addEventListener("resize", initSeeds);
                const seedIO = new IntersectionObserver((en) => {
                  var _a2;
                  running = !!((_a2 = en[0]) == null
                    ? void 0
                    : _a2.isIntersecting);
                });
                seedIO.observe(seedCanvas);
                cleanups.push(() => {
                  alive = false;
                  running = false;
                  host == null
                    ? void 0
                    : host.removeEventListener("pointermove", onPtr);
                  host == null
                    ? void 0
                    : host.removeEventListener("pointerleave", onPtrLeave);
                  window.removeEventListener("resize", initSeeds);
                  seedIO.disconnect();
                });
              }
            }
            const faqItems = $$(".faq-list details");
            const faqHandlers = faqItems.map((d) => {
              const h = () => {
                if (d.open)
                  faqItems.forEach((o) => {
                    if (o !== d) o.open = false;
                  });
              };
              d.addEventListener("toggle", h);
              return () => d.removeEventListener("toggle", h);
            });
            cleanups.push(() => faqHandlers.forEach((h) => h()));
            const toastEl = $("#toast");
            let toastTimer = 0;
            const toast = (msg) => {
              const node = $(".toast-msg", toastEl);
              if (node) node.textContent = msg;
              toastEl == null ? void 0 : toastEl.classList.add("is-visible");
              window.clearTimeout(toastTimer);
              toastTimer = window.setTimeout(
                () =>
                  toastEl == null
                    ? void 0
                    : toastEl.classList.remove("is-visible"),
                4200,
              );
            };
            const form = $("#inquiry-form");
            const success = $("#form-success");
            const mark = (input, msg) => {
              const field = input.closest(".field");
              field == null ? void 0 : field.classList.add("is-error");
              const err =
                field == null ? void 0 : field.querySelector(".field-error");
              if (err) err.textContent = msg;
            };
            const onSubmit = (e) => {
              e.preventDefault();
              if (!form || !success) return;
              const website = form.elements.namedItem("website");
              if (website && website.value) return;
              $$(".field", form).forEach((f) => f.classList.remove("is-error"));
              $$(".field-error", form).forEach((fe) => {
                fe.textContent = "";
              });
              const name = form.elements.namedItem("name");
              const email = form.elements.namedItem("email");
              const message = form.elements.namedItem("message");
              const topic = form.elements.namedItem("topic");
              let ok = true;
              if (name.value.trim().length < 2) {
                mark(name, "Please tell us your name.");
                ok = false;
              }
              if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email.value.trim())) {
                mark(
                  email,
                  "That email doesn't look right \u2014 mind checking it?",
                );
                ok = false;
              }
              if (message.value.trim().length < 8) {
                mark(message, "A sentence or two helps us reply well.");
                ok = false;
              }
              if (!ok) return;
              const btn = $("#form-submit");
              const endpoint = form.getAttribute("data-endpoint");
              const finish = (title, text, note) => {
                form.hidden = true;
                success.hidden = false;
                const titleEl = $("#success-title");
                const textEl = $("#success-text");
                if (titleEl) titleEl.textContent = title;
                if (textEl) textEl.textContent = text;
                btn == null ? void 0 : btn.classList.remove("is-loading");
                if (btn) btn.disabled = false;
                toast(note);
                form.reset();
              };
              btn == null ? void 0 : btn.classList.add("is-loading");
              if (btn) btn.disabled = true;
              if (endpoint) {
                fetch(endpoint, {
                  method: "POST",
                  body: new FormData(form),
                  headers: { Accept: "application/json" },
                })
                  .then((r) => {
                    if (!r.ok) throw new Error(String(r.status));
                    finish(
                      "Message received.",
                      "Thank you \u2014 a real person will read it and reply, usually within two business days.",
                      "Thank you \u2014 your message has been sent.",
                    );
                  })
                  .catch(() => {
                    btn == null ? void 0 : btn.classList.remove("is-loading");
                    if (btn) btn.disabled = false;
                    toast(
                      "We couldn\u2019t send that. Please email contact@altasfarm.life instead.",
                    );
                  });
              } else {
                const body =
                  "Name: " +
                  name.value.trim() +
                  "\nEmail: " +
                  email.value.trim() +
                  "\nTopic: " +
                  topic.value +
                  "\n\n" +
                  message.value.trim();
                window.location.href =
                  "mailto:" +
                  form.getAttribute("data-mailto") +
                  "?subject=" +
                  encodeURIComponent("Website inquiry \u2014 " + topic.value) +
                  "&body=" +
                  encodeURIComponent(body);
                finish(
                  "Your email is ready to send.",
                  "We opened your email app with your message filled in \u2014 press send there. If nothing opened, write to contact@altasfarm.life.",
                  "Almost done \u2014 press send in your email app.",
                );
              }
            };
            form == null ? void 0 : form.addEventListener("submit", onSubmit);
            const again = $("#send-another");
            const onAgain = () => {
              if (!form || !success) return;
              success.hidden = true;
              form.hidden = false;
            };
            again == null ? void 0 : again.addEventListener("click", onAgain);
            cleanups.push(() => {
              form == null
                ? void 0
                : form.removeEventListener("submit", onSubmit);
              again == null
                ? void 0
                : again.removeEventListener("click", onAgain);
              window.clearTimeout(toastTimer);
            });
            const onTop = () => {
              window.scrollTo({
                top: 0,
                behavior: reduced ? "auto" : "smooth",
              });
              const home = $("#home");
              if (home) {
                home.setAttribute("tabindex", "-1");
                home.focus({ preventScroll: true });
              }
            };
            toTop == null ? void 0 : toTop.addEventListener("click", onTop);
            cleanups.push(() =>
              toTop == null
                ? void 0
                : toTop.removeEventListener("click", onTop),
            );
            const yearEl = $("#year");
            if (yearEl)
              yearEl.textContent = String(
                /* @__PURE__ */ new Date().getFullYear(),
              );
            return () => cleanups.forEach((fn) => fn());
          }, []);
        }

        useAltasMotion();
      })();
    </script>

    <script>
      /* Free-roaming chickens in the hero scene.
         They wander the field, stop to peck, and now and then walk off one edge of
         the card and wander back in from the opposite edge. They stay still when the
         visitor prefers reduced motion. */
      (() => {
        const root = document.querySelector(".chickens");
        const visual = document.getElementById("hero-visual");
        if (!root || !visual) return;
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

        const TAU = Math.PI * 2;
        const Y0 = 490, Y1 = 634; // walkable band of the field (scene units)
        const XL = -50, XR = 650; // just outside the card's left / right edge
        const rnd = (a, b) => a + Math.random() * (b - a);
        const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
        const randY = () => Y0 + (Y1 - Y0) * Math.pow(Math.random(), 1.35);
        const scaleOf = (b) => b.base * (0.82 + 0.3 * clamp((b.y - Y0) / (Y1 - Y0), 0, 1));
        const rot = (a, pivot) => `rotate(${a.toFixed(1)} ${pivot})`;

        // pivots (local coordinates) and motion ranges for each kind of bird
        const KIND = {
          hen: { legA: "0 -7", legB: "6 -7", body: "2 -9", neck: "11 -21", swing: 26, tilt: 40, nod: 45, bob: 0.9, stride: 14, peckHz: 3 },
          chick: { legA: "-1.5 -3.6", legB: "2.5 -3.6", body: "3 -3.6", swing: 36, tilt: 50, bob: 1.1, stride: 8, peckHz: 3.8 }
        };

        const flock = [...root.querySelectorAll(".chicken")].map((el) => {
          const hen = el.classList.contains("hen");
          return {
            el, hen, k: hen ? KIND.hen : KIND.chick, base: hen ? 1 : 1.26,
            body: el.querySelector(".hen-body, .chick-body"),
            neck: el.querySelector(".hen-neck"),
            legA: el.querySelector(".leg-a"),
            legB: el.querySelector(".leg-b"),
            x: parseFloat(el.dataset.x), y: parseFloat(el.dataset.y), dir: parseFloat(el.dataset.dir) || 1,
            state: "idle", t: 0, dur: rnd(0.2, 1.8),
            tx: 0, ty: 0, speed: 0, side: 0, leaving: false,
            walk: 0, peck: 0, gate: 0, pt: rnd(0, 5), step: rnd(0, TAU)
          };
        });

        const startWalk = (b, tx, ty, speed) => {
          b.state = "walk"; b.tx = tx; b.ty = ty; b.speed = speed;
          b.dir = tx >= b.x ? 1 : -1;
        };
        const speedOf = (b) => (b.hen ? rnd(24, 38) : rnd(34, 54));

        const wander = (b) => {
          let tx = b.x + rnd(50, 230) * (Math.random() < 0.5 ? -1 : 1);
          if (tx < 28 || tx > 572) tx = 2 * b.x - tx; // bounce back inside the card
          const ty = Math.random() < 0.3 ? randY() : clamp(b.y + rnd(-24, 24), Y0, Y1);
          startWalk(b, clamp(tx, 28, 572), ty, speedOf(b));
        };

        // walk out through the left or right edge, then come back in on the other side
        const leave = (b) => {
          b.side = Math.random() < 0.5 ? -1 : 1;
          b.leaving = true;
          startWalk(b, b.side < 0 ? XL - 10 : XR + 10, clamp(b.y + rnd(-14, 14), Y0, Y1), speedOf(b) * 1.1);
        };

        const reenter = (b) => {
          b.x = b.side < 0 ? XR : XL; // left via the left edge -> reappears at the right edge
          b.y = randY();
          b.el.style.display = "";
          startWalk(b, b.side < 0 ? rnd(380, 540) : rnd(60, 220), clamp(b.y + rnd(-18, 18), Y0, Y1), speedOf(b));
          b.t = 0;
          draw(b);
        };

        const decide = (b, afterWalk) => {
          const r = Math.random();
          const peck = () => { b.state = "peck"; b.dur = b.hen ? rnd(1.6, 4.4) : rnd(1.0, 3.2); };
          const idle = () => { b.state = "idle"; b.dur = rnd(0.4, 1.6); };
          if (afterWalk) {
            if (r < 0.68) peck(); else if (r < 0.84) idle(); else wander(b);
          } else if (r < 0.55) {
            Math.random() < 0.12 ? leave(b) : wander(b);
          } else if (r < 0.88) peck(); else idle();
          b.t = 0;
        };

        const update = (b, dt) => {
          if (b.state === "away") {
            b.t += dt;
            if (b.t >= b.dur) reenter(b);
            return;
          }
          b.t += dt;
          let walking = 0, pecking = 0;
          if (b.state === "walk") {
            const dx = b.tx - b.x, dy = b.ty - b.y, d = Math.hypot(dx, dy), len = b.speed * dt;
            if (d <= len + 0.5) {
              b.x = b.tx; b.y = b.ty;
              if (b.leaving) {
                b.leaving = false; b.state = "away"; b.t = 0; b.dur = rnd(1.5, 6);
                b.el.style.display = "none";
                return;
              }
              decide(b, true);
            } else {
              b.x += (dx / d) * len; b.y += (dy / d) * len;
              if (Math.abs(dx) > 1.5) b.dir = dx > 0 ? 1 : -1;
              b.step += (dt * b.speed) / (b.k.stride * scaleOf(b)) * TAU;
              walking = 1;
            }
          } else if (b.state === "peck") {
            pecking = 1; b.pt += dt;
            if (b.t >= b.dur) decide(b, false);
          } else if (b.t >= b.dur) decide(b, false);

          // ease in / out of walking and pecking so poses never snap
          const e = 1 - Math.exp(-dt * 10);
          b.walk += (walking - b.walk) * e;
          b.peck += (pecking - b.peck) * e;
          const burst = (b.pt * 0.6) % 1 < 0.72 ? 1 : 0; // a few pecks, then a look up
          b.gate += (burst - b.gate) * (1 - Math.exp(-dt * 14));
          draw(b);
        };

        const draw = (b) => {
          const k = b.k, s = Math.sin(b.step), w = b.walk;
          const wave = Math.pow(Math.max(0, Math.sin(Math.PI * ((b.pt * k.peckHz) % 1))), 1.4);
          const p = b.peck * b.gate * wave;
          b.legA.setAttribute("transform", rot(k.swing * w * s, k.legA));
          b.legB.setAttribute("transform", rot(-k.swing * w * s, k.legB));
          b.body.setAttribute("transform", `translate(0 ${(-Math.abs(s) * k.bob * w).toFixed(2)}) ${rot(k.tilt * p, k.body)}`);
          if (b.neck) b.neck.setAttribute("transform", rot(k.nod * p + 6 * w * Math.sin(b.step - 0.9), k.neck));
          const sc = scaleOf(b);
          b.el.setAttribute("transform", `translate(${b.x.toFixed(2)} ${b.y.toFixed(2)}) scale(${(b.dir * sc).toFixed(3)} ${sc.toFixed(3)})`);
        };

        // keep the one lower on screen in front of the one behind it
        const zsort = () => {
          const order = flock.slice().sort((a, b) => a.y - b.y);
          if (order.some((b, i) => root.children[i] !== b.el)) order.forEach((b) => root.appendChild(b.el));
        };

        let running = false, last = 0, since = 0;
        const frame = (now) => {
          if (!running) return;
          const dt = Math.min(0.05, (now - last) / 1000 || 0.016);
          last = now;
          flock.forEach((b) => update(b, dt));
          since += dt;
          if (since > 0.25) { since = 0; zsort(); }
          requestAnimationFrame(frame);
        };
        const start = () => { if (!running) { running = true; last = performance.now(); requestAnimationFrame(frame); } };
        const stop = () => { running = false; };

        if ("IntersectionObserver" in window) {
          new IntersectionObserver((entries) => (entries[0].isIntersecting ? start() : stop())).observe(visual);
        } else {
          start();
        }
      })();
    </script>

    <script>
      /* Sunflower petals in the hero scene.
         Same approach as the chickens: static SVG markup, with every frame's
         transform written from here. Each petal breathes almost imperceptibly;
         every so often the wind takes one away — up into the sky, or sideways
         left / right, chosen at random — and a fresh petal pops into its slot
         while the loose one is still drifting. Only one petal is ever in flight.
         With reduced motion the flower is left as static art. */
      (() => {
        const root = document.querySelector(".petals");
        const visual = document.getElementById("hero-visual");
        const flightEl = document.querySelector(".petal-flight");
        if (!root || !visual || !flightEl) return;
        if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

        const TAU = Math.PI * 2;
        const CX = 300, CY = 484, PIVOT_Y = 464; // head centre, petal middle
        const rnd = (a, b) => a + Math.random() * (b - a);
        const easeOutBack = (t) => 1 + 2.4 * Math.pow(t - 1, 3) + 1.4 * Math.pow(t - 1, 2);

        const petals = [...root.querySelectorAll(".petal")].map((el) => ({
          el,
          a: parseFloat(el.dataset.a) || 0,
          phase: rnd(0, TAU),
          rate: rnd(0.45, 0.85),   // slow, uneven breathing
          amp: rnd(0.35, 0.65),    // degrees: barely there
          sway: 0,
          s: 1,
          state: "on",             // on | out | grow
          timer: 0,
          wait: 0
        }));

        const drawPetal = (p) => {
          p.el.setAttribute(
            "transform",
            `rotate(${(p.a + p.sway).toFixed(2)} ${CX} ${CY}) ` +
              `translate(${CX} ${CY}) scale(${p.s.toFixed(3)}) translate(${-CX} ${-CY})`
          );
        };

        let flight = null, next = rnd(4, 7), clock = 0;

        const drawFlight = () => {
          const f = flight;
          const p = Math.min(1, f.t / f.dur);
          const fade = p > f.fadeAt ? Math.max(0, 1 - (p - f.fadeAt) / (1 - f.fadeAt)) : 1;
          let x, y;
          if (f.axis === "up") {
            x = f.amp * Math.sin(f.t * f.freq + f.phase) * Math.min(1, f.t / 1.6) + f.drift * f.t;
            y = -f.speed * f.t;
          } else {
            x = f.dir * f.speed * f.t;
            y = -f.bow * Math.sin(p * Math.PI) - 5 * p;
          }
          const spin = f.spin + f.spinV * f.t;
          flightEl.setAttribute("opacity", fade.toFixed(3));
          flightEl.setAttribute(
            "transform",
            `translate(${x.toFixed(2)} ${y.toFixed(2)}) ` +
              `rotate(${f.ang.toFixed(2)} ${CX} ${CY}) rotate(${spin.toFixed(2)} ${CX} ${PIVOT_Y})`
          );
        };

        const launch = () => {
          const ready = petals.filter((p) => p.state === "on");
          if (!ready.length) return false;
          const p = ready[(Math.random() * ready.length) | 0];
          const axis = Math.random() < 0.5 ? "up" : "side";
          const dir = Math.random() < 0.5 ? -1 : 1;
          flight = {
            axis, dir,
            ang: p.a + p.sway,
            t: 0,
            dur: axis === "up" ? rnd(9, 11) : rnd(7, 9),
            speed: axis === "up" ? rnd(26, 34) : rnd(30, 40),
            amp: rnd(10, 16),
            freq: rnd(0.8, 1.3),
            phase: rnd(0, TAU),
            drift: rnd(-2.2, 2.2),
            bow: rnd(10, 16),
            spin: 0,
            spinV: rnd(14, 28) * (Math.random() < 0.5 ? -1 : 1),
            fadeAt: axis === "up" ? 0.82 : 0.74
          };
          flightEl.querySelector("path").setAttribute("fill", p.el.querySelector("path").getAttribute("fill"));
          flightEl.setAttribute("opacity", "1");
          p.state = "out";
          p.s = 0.001; // the flight copy covers the slot exactly
          p.wait = 5;  // hold the empty slot for a beat before the new petal pops in
          drawPetal(p);
          drawFlight();
          return true;
        };

        const update = (dt) => {
          clock += dt;
          for (const p of petals) {
            if (p.state === "out") {
              p.wait -= dt;
              if (p.wait <= 0) { p.state = "grow"; p.timer = 0; p.s = 0.001; }
            } else if (p.state === "grow") {
              p.timer += dt;
              const t = Math.min(1, p.timer / 0.52);
              p.s = Math.max(0.001, Math.min(1.06, easeOutBack(t)));
              if (t >= 1) { p.s = 1; p.state = "on"; }
            } else {
              p.sway =
                p.amp * Math.sin(clock * p.rate + p.phase) +
                p.amp * 0.38 * Math.sin(clock * p.rate * 1.71 + p.phase * 2.1);
            }
            drawPetal(p);
          }

          if (flight) {
            flight.t += dt;
            if (flight.t >= flight.dur) {
              flightEl.setAttribute("opacity", "0");
              flight = null;
              next = rnd(6, 12);
            } else {
              drawFlight();
            }
          } else {
            next -= dt;
            if (next <= 0 && !launch()) next = 0.8;
          }
        };

        let running = false, last = 0;
        const frame = (now) => {
          if (!running) return;
          const dt = Math.min(0.05, (now - last) / 1000 || 0.016);
          last = now;
          update(dt);
          requestAnimationFrame(frame);
        };
        const start = () => { if (!running) { running = true; last = performance.now(); requestAnimationFrame(frame); } };
        const stop = () => { running = false; };

        if ("IntersectionObserver" in window) {
          new IntersectionObserver((entries) => (entries[0].isIntersecting ? start() : stop())).observe(visual);
        } else {
          start();
        }
      })();
    </script>
  </body>
</html>
