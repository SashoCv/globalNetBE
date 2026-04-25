<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="UTF-8">
    <title>Global Net ADV — Дигитални услуги за настани</title>
    <style>
        @page { margin: 0; }
        * { font-family: DejaVu Sans, sans-serif; box-sizing: border-box; padding: 0; margin: 0; }
        body { font-size: 10pt; color: #0f172a; line-height: 1.5; }

        .page {
            page-break-after: always;
            padding: 14mm 14mm;
            position: relative;
        }
        .page:last-child { page-break-after: auto; }

        /* ── COVER ─────────────────────────────────────────────── */
        .cover {
            background: #6d28d9;
            color: #fff;
            padding: 0;
            position: relative;
        }
        .cover-band-top {
            background: #5b21b6;
            padding: 10mm 14mm 5mm;
        }
        .cover-brand {
            font-size: 9pt;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #ddd6fe;
            font-weight: bold;
        }
        .cover-body { padding: 26mm 14mm 12mm; }
        .cover-eyebrow {
            font-size: 11pt;
            color: #ddd6fe;
            margin-bottom: 10mm;
            letter-spacing: 0.05em;
        }
        .cover-title {
            font-size: 38pt;
            font-weight: bold;
            line-height: 1.05;
            margin-bottom: 5mm;
            color: #fff;
        }
        .cover-subtitle {
            font-size: 14pt;
            color: #ede9fe;
            line-height: 1.4;
            margin-bottom: 14mm;
            max-width: 145mm;
        }
        .cover-divider {
            width: 50mm;
            height: 4px;
            background: #fbbf24;
            margin-bottom: 12mm;
        }
        .cover-services { margin-top: 6mm; }
        .cover-services td { padding: 4mm 6mm 4mm 0; vertical-align: top; }
        .cover-srv-num {
            font-size: 28pt;
            color: #fbbf24;
            font-weight: bold;
            line-height: 1;
        }
        .cover-srv-title {
            font-size: 13pt;
            font-weight: bold;
            margin-top: 2mm;
            color: #fff;
        }
        .cover-srv-desc {
            font-size: 9pt;
            color: #ddd6fe;
            margin-top: 1mm;
        }
        .cover-footer {
            position: absolute;
            bottom: 14mm;
            left: 14mm;
            right: 14mm;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: 5mm;
            color: #c4b5fd;
            font-size: 9pt;
        }
        .cover-footer table { width: 100%; }

        /* ── SECTION HEADERS ───────────────────────────────────── */
        .sec-eyebrow {
            font-size: 8.5pt;
            color: #7c3aed;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            font-weight: bold;
            margin-bottom: 2mm;
        }
        .sec-title {
            font-size: 22pt;
            color: #0f172a;
            font-weight: bold;
            line-height: 1.1;
            margin-bottom: 3mm;
        }
        .sec-lead {
            font-size: 11pt;
            color: #475569;
            line-height: 1.5;
            margin-bottom: 6mm;
            max-width: 165mm;
        }
        .sec-divider {
            width: 22mm;
            height: 3px;
            background: #7c3aed;
            margin-bottom: 4mm;
        }
        .sub-eyebrow {
            font-size: 8.5pt;
            color: #7c3aed;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-weight: bold;
            margin-top: 8mm;
            margin-bottom: 3mm;
        }

        /* ── SERVICE CARDS ─────────────────────────────────────── */
        .cards-3 { width: 100%; border-collapse: separate; border-spacing: 3mm 0; }
        .cards-3 td { width: 33.33%; vertical-align: top; }

        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-top: 4px solid #7c3aed;
            border-radius: 5px;
            padding: 6mm 5mm;
        }
        .card--alt { border-top-color: #0891b2; }
        .card--alt2 { border-top-color: #d97706; }

        .card-num {
            display: inline-block;
            background: #ede9fe;
            color: #7c3aed;
            font-size: 14pt;
            font-weight: bold;
            width: 11mm;
            height: 11mm;
            line-height: 11mm;
            text-align: center;
            border-radius: 50%;
            margin-bottom: 3mm;
        }
        .card--alt .card-num { background: #cffafe; color: #0891b2; }
        .card--alt2 .card-num { background: #fef3c7; color: #d97706; }

        .card-title {
            font-size: 12pt;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 2mm;
        }
        .card-text {
            font-size: 9pt;
            color: #475569;
            line-height: 1.5;
        }

        /* ── FEATURE BOX ─────────────────────────────────────── */
        .featbox {
            background: #f5f3ff;
            border-left: 4px solid #7c3aed;
            border-radius: 4px;
            padding: 4mm 6mm;
            margin: 5mm 0;
        }
        .featbox-title {
            font-weight: bold;
            color: #5b21b6;
            font-size: 11pt;
            margin-bottom: 2mm;
        }
        .featbox-text {
            color: #475569;
            font-size: 9.5pt;
            line-height: 1.5;
        }

        /* ── BENEFITS LIST ──────────────────────────────────── */
        .benefits {
            margin-top: 4mm;
            border-collapse: collapse;
            width: 100%;
        }
        .benefits td {
            padding: 3mm 0;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .benefits .check {
            width: 8mm;
            color: #16a34a;
            font-weight: bold;
            font-size: 12pt;
            line-height: 1;
        }
        .benefits .label {
            color: #0f172a;
            font-weight: bold;
            font-size: 10pt;
            width: 45mm;
            padding-right: 5mm;
        }
        .benefits .desc {
            color: #64748b;
            font-size: 9.5pt;
            line-height: 1.45;
        }

        /* ── STEPS ──────────────────────────────────────────── */
        .steps { width: 100%; border-collapse: separate; border-spacing: 3mm 0; }
        .steps td { width: 25%; vertical-align: top; }

        .step {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 5mm 4mm;
        }
        .step-num {
            background: #7c3aed;
            color: #fff;
            font-size: 12pt;
            font-weight: bold;
            width: 9mm;
            height: 9mm;
            line-height: 9mm;
            text-align: center;
            border-radius: 50%;
            margin-bottom: 3mm;
        }
        .step-title {
            font-size: 10pt;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 2mm;
        }
        .step-text {
            font-size: 8.5pt;
            color: #64748b;
            line-height: 1.4;
        }

        /* ── HIGHLIGHT BAR ──────────────────────────────────── */
        .hl-bar {
            background: #6d28d9;
            color: #fff;
            padding: 6mm 8mm;
            border-radius: 5px;
            margin: 6mm 0;
        }
        .hl-bar-title {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 2mm;
        }
        .hl-bar-text {
            font-size: 9.5pt;
            color: #ede9fe;
            line-height: 1.5;
        }

        /* ── STATS ROW ─────────────────────────────────────── */
        .stats {
            width: 100%;
            border-collapse: separate;
            border-spacing: 3mm 0;
            margin-top: 5mm;
        }
        .stats td {
            background: #f8fafc;
            border-top: 4px solid #7c3aed;
            border-radius: 5px;
            padding: 5mm 4mm;
            text-align: center;
            width: 33.33%;
        }
        .stat-num {
            font-size: 22pt;
            font-weight: bold;
            color: #7c3aed;
            line-height: 1;
        }
        .stat-lbl {
            font-size: 8.5pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-top: 2mm;
        }

        /* ── OPTIONS GRID ──────────────────────────────── */
        .opts { width: 100%; border-collapse: separate; border-spacing: 3mm 0; margin-top: 3mm; }
        .opts td {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 5mm;
            vertical-align: top;
            width: 33.33%;
        }
        .opt-icon {
            font-size: 16pt;
            color: #7c3aed;
            line-height: 1;
            margin-bottom: 2mm;
        }
        .opt-title { font-weight: bold; font-size: 10pt; color: #0f172a; margin-bottom: 2mm; }
        .opt-text { font-size: 9pt; color: #64748b; line-height: 1.45; }

        /* ── PILL TAGS ───────────────────────────────────── */
        .pills { margin-top: 4mm; }
        .pill {
            display: inline-block;
            background: #ede9fe;
            color: #5b21b6;
            font-size: 9pt;
            padding: 2mm 4mm;
            border-radius: 999px;
            margin: 0 1mm 2mm 0;
            font-weight: bold;
        }

        /* ── FOOTER ────────────────────────────────────────── */
        .pg-footer {
            margin-top: 10mm;
            font-size: 8pt;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 3mm;
        }
        .pg-footer table { width: 100%; }
        .pg-footer td { vertical-align: middle; }
        .pg-footer .right { text-align: right; }

        /* ── CONTACT ───────────────────────────────────────── */
        .contact {
            background: #0f172a;
            color: #fff;
            padding: 26mm 14mm;
        }
        .contact-eyebrow {
            font-size: 10pt;
            color: #c4b5fd;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            font-weight: bold;
            margin-bottom: 5mm;
        }
        .contact-title {
            font-size: 32pt;
            font-weight: bold;
            line-height: 1.1;
            margin-bottom: 6mm;
        }
        .contact-lead {
            font-size: 13pt;
            color: #cbd5e1;
            line-height: 1.5;
            margin-bottom: 12mm;
            max-width: 150mm;
        }
        .contact-divider {
            width: 50mm;
            height: 4px;
            background: #fbbf24;
            margin-bottom: 12mm;
        }
        .contact-info { width: 100%; border-collapse: collapse; }
        .contact-info td {
            padding: 5mm 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            vertical-align: top;
            color: #fff;
        }
        .contact-info .ci-lbl {
            width: 32mm;
            color: #c4b5fd;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: bold;
        }
        .contact-info .ci-val {
            font-size: 12pt;
            font-weight: 500;
        }
        .contact-cta {
            background: #7c3aed;
            border-radius: 6px;
            padding: 8mm;
            margin-top: 14mm;
            text-align: center;
        }
        .contact-cta-title {
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 2mm;
        }
        .contact-cta-text {
            font-size: 10pt;
            color: #ede9fe;
        }
    </style>
</head>
<body>

{{-- ════════ COVER ════════ --}}
<div class="page cover">
    <div class="cover-band-top">
        <div class="cover-brand">Global Net ADV · Скопје · 30+ години искуство</div>
    </div>

    <div class="cover-body">
        <div class="cover-eyebrow">ДИГИТАЛНА ПРЕЗЕНТАЦИЈА НА УСЛУГИ</div>
        <div class="cover-title">
            Дигитализирајте го<br>
            искуството на<br>
            вашиот штанд
        </div>
        <div class="cover-divider"></div>
        <div class="cover-subtitle">
            Следете го присуството во реално време, собирајте евалуации преку QR код
            и претставете се пред публиката со интерактивни маркетинг страници —
            подготвени и водени од нашиот тим.
        </div>

        <table class="cover-services">
            <tr>
                <td>
                    <div class="cover-srv-num">01</div>
                    <div class="cover-srv-title">Следење на<br>присуство</div>
                    <div class="cover-srv-desc">QR check-in</div>
                </td>
                <td>
                    <div class="cover-srv-num">02</div>
                    <div class="cover-srv-title">Дигитални<br>евалуации</div>
                    <div class="cover-srv-desc">Прашалници со резултати</div>
                </td>
                <td>
                    <div class="cover-srv-num">03</div>
                    <div class="cover-srv-title">Маркетинг<br>презентации</div>
                    <div class="cover-srv-desc">Landing страници</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="cover-footer">
        <table>
            <tr>
                <td>globalnetadv.mk</td>
                <td style="text-align: right;">Информативен преглед на услуги</td>
            </tr>
        </table>
    </div>
</div>

{{-- ════════ PAGE 2: ОВЕРВИЕВ + WORKFLOW ════════ --}}
<div class="page">
    <div class="sec-eyebrow">ШТО НУДИМЕ</div>
    <div class="sec-title">Три решенија. Една платформа.</div>
    <div class="sec-divider"></div>
    <div class="sec-lead">
        Нашата дигитална платформа ги обединува сите алатки потребни за модерен настан.
        Без инсталации за вашите посетители — само скенирање на QR код. Без техничка
        работа за вашиот тим — сè го подготвуваме ние.
    </div>

    <table class="cards-3">
        <tr>
            <td>
                <div class="card">
                    <div class="card-num">1</div>
                    <div class="card-title">Следење на присуство</div>
                    <div class="card-text">
                        Дознајте точно колку посетители имал вашиот штанд, сателитска
                        сесија или стручен состанок. Секој скен на QR кодот се евидентира
                        автоматски.
                    </div>
                </div>
            </td>
            <td>
                <div class="card card--alt">
                    <div class="card-num">2</div>
                    <div class="card-title">Дигитални евалуации</div>
                    <div class="card-text">
                        Прашалници со повеќе типови прашања, режими на анонимност,
                        реал-тајм собирање и професионални PDF извештаи готови за
                        внатрешна употреба.
                    </div>
                </div>
            </td>
            <td>
                <div class="card card--alt2">
                    <div class="card-num">3</div>
                    <div class="card-title">Маркетинг презентации</div>
                    <div class="card-text">
                        Landing страница за вашиот бренд со hero слика, содржина,
                        галерија и call-to-action. Достапна за времетраењето на
                        настанот преку QR код.
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div class="sub-eyebrow">КАКО ФУНКЦИОНИРА</div>
    <table class="steps">
        <tr>
            <td>
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-title">Договор</div>
                    <div class="step-text">
                        Кратка средба за да дефинираме што ви треба — сесии за следење,
                        прашања за евалуација, материјал за презентација.
                    </div>
                </div>
            </td>
            <td>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-title">Подготовка</div>
                    <div class="step-text">
                        Нашиот тим креира сè — сесии, прашалници, презентации со ваши
                        слики и текстови. Ви испраќаме готови QR кодови за печатење.
                    </div>
                </div>
            </td>
            <td>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-title">Настан</div>
                    <div class="step-text">
                        Ние ги активираме QR кодовите на денот. Посетителите скенираат —
                        системот ги запишува податоците автоматски.
                    </div>
                </div>
            </td>
            <td>
                <div class="step">
                    <div class="step-num">4</div>
                    <div class="step-title">Извештаи</div>
                    <div class="step-text">
                        После настанот ви доставуваме PDF извештаи и CSV податоци —
                        агрегирани, прегледни, готови за презентирање.
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div class="pg-footer">
        <table>
            <tr>
                <td>Global Net ADV — Дигитални услуги за настани</td>
                <td class="right">Стр. 2</td>
            </tr>
        </table>
    </div>
</div>

{{-- ════════ PAGE 3: ATTENDANCE ════════ --}}
<div class="page">
    <div class="sec-eyebrow">УСЛУГА 1</div>
    <div class="sec-title">Следење на присуство</div>
    <div class="sec-divider"></div>
    <div class="sec-lead">
        Поставете QR код на штандот, влезот на сесијата или работилницата. Секој
        посетител се регистрира за неколку секунди — името и кратки податоци остануваат
        во вашиот извештај.
    </div>

    <div class="featbox">
        <div class="featbox-title">Како работи</div>
        <div class="featbox-text">
            Посетителот скенира QR код → отвора кратка форма (име, презиме, е-пошта,
            град) → се регистрира → ние го следиме броењето во админ панелот во живо.
            По настанот, ви ги доставуваме податоците.
        </div>
    </div>

    <table class="benefits">
        <tr>
            <td class="check">✓</td>
            <td class="label">Точна евиденција</td>
            <td class="desc">Реално броење на посетители за секоја сесија или штанд — без рачни листи и без претпоставки.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Категоризација</td>
            <td class="desc">Сесии групирани по тип — сателитски симпозиум, штанд, стручен состанок, регистрација, тркалезна маса.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Подетална статистика</td>
            <td class="desc">Кои биле повторливи посетители на повеќе сесии, процентуална посетеност, листи по град/професија.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">CSV извоз</td>
            <td class="desc">Преземете ги податоците за внатрешна анализа, CRM систем или follow-up маркетинг кампањи.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Контрола на сесија</td>
            <td class="desc">QR кодот го активираме само за времетраењето на сесијата — никој не може да се регистрира пред или после.</td>
        </tr>
    </table>

    <table class="stats">
        <tr>
            <td>
                <div class="stat-num">5 сек</div>
                <div class="stat-lbl">просечно време за регистрација</div>
            </td>
            <td>
                <div class="stat-num">100%</div>
                <div class="stat-lbl">точна евиденција</div>
            </td>
            <td>
                <div class="stat-num">∞</div>
                <div class="stat-lbl">број на сесии и QR кодови</div>
            </td>
        </tr>
    </table>

    <div class="pg-footer">
        <table>
            <tr>
                <td>Global Net ADV — Дигитални услуги за настани</td>
                <td class="right">Стр. 3</td>
            </tr>
        </table>
    </div>
</div>

{{-- ════════ PAGE 4: EVALUATIONS ════════ --}}
<div class="page">
    <div class="sec-eyebrow">УСЛУГА 2</div>
    <div class="sec-title">Дигитални евалуации</div>
    <div class="sec-divider"></div>
    <div class="sec-lead">
        Прашалник со QR код покрај штандот собира мислења во реално време. Идеално
        за мерење задоволство, истражување на пазарот, фидбек од учесници или брза
        анкета за нов производ. Ние го креираме прашалникот според ваши потреби.
    </div>

    <div class="featbox">
        <div class="featbox-title">Пет типови прашања</div>
        <div class="featbox-text">
            Краток текст · долг текст · single choice (радио копчиња) · multiple choice
            (checkbox) · рејтинг 1–10 со прилагодлива скала. Секое прашање може да биде
            задолжително или опционално.
        </div>
    </div>

    <div class="sub-eyebrow" style="margin-top: 5mm;">ТРИ РЕЖИМИ НА АНОНИМНОСТ</div>
    <table class="opts">
        <tr>
            <td>
                <div class="opt-icon">●</div>
                <div class="opt-title">Само анонимно</div>
                <div class="opt-text">
                    Никакви лични податоци не се собираат. Идеално за искрени мислења
                    и чувствителни прашања.
                </div>
            </td>
            <td>
                <div class="opt-icon">●</div>
                <div class="opt-title">Само со податоци</div>
                <div class="opt-text">
                    Бара име, презиме, е-пошта и телефон. Знаете точно кој што
                    одговорил — корисно за follow-up.
                </div>
            </td>
            <td>
                <div class="opt-icon">●</div>
                <div class="opt-title">И двете</div>
                <div class="opt-text">
                    Корисникот сам бира при пополнување. Максимална флексибилност за
                    широка публика со различни преференци.
                </div>
            </td>
        </tr>
    </table>

    <table class="benefits">
        <tr>
            <td class="check">✓</td>
            <td class="label">Професионален PDF</td>
            <td class="desc">По настанот испраќаме извештај со агрегирани графикони, просечни рејтинзи и список на идентификувани респонденти.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Брендирано со ваше лого</td>
            <td class="desc">Логото на фирмата се прикажува горе-десно на самата евалуација кога посетителите ја пополнуваат.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Мобилно оптимизирана</td>
            <td class="desc">Изгледа и работи беспрекорно на телефон. Без апликации, без логин — кратка форма за неколку секунди.</td>
        </tr>
    </table>

    <div class="pg-footer">
        <table>
            <tr>
                <td>Global Net ADV — Дигитални услуги за настани</td>
                <td class="right">Стр. 4</td>
            </tr>
        </table>
    </div>
</div>

{{-- ════════ PAGE 5: PRESENTATIONS ════════ --}}
<div class="page">
    <div class="sec-eyebrow">УСЛУГА 3</div>
    <div class="sec-title">Маркетинг презентации</div>
    <div class="sec-divider"></div>
    <div class="sec-lead">
        Мобилно оптимизирана landing страница за вашиот бренд, производ или услуга,
        достапна за времетраењето на настанот преку QR код покрај штандот. Сите
        слики, текстови и линкови ги подготвуваме и активираме ние.
    </div>

    <div class="featbox">
        <div class="featbox-title">Што содржи презентацијата</div>
        <div class="featbox-text">
            Hero банер со слика и наслов · содржина форматирана со истакнати делови
            и листи · фото галерија со lightbox преглед · јасно call-to-action копче
            (линк до вашиот сајт, контакт форма или нарачка).
        </div>
    </div>

    <table class="benefits">
        <tr>
            <td class="check">✓</td>
            <td class="label">Достапна за настанот</td>
            <td class="desc">Активна за времетраењето на настанот — посетителите можат да ја отворат, разгледаат и споделат со колеги.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Подготовка од наш тим</td>
            <td class="desc">Ни ги испраќате слики, лого, текст и линкови — ние ги обработуваме, форматираме и ја активираме страницата на ден на настан.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Мобилно оптимизирана</td>
            <td class="desc">Брзо вчитување на телефон, чист дизајн, читлив на секаков уред. Изгледа професионално, како квалитетна landing страница.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Богато форматирање</td>
            <td class="desc">Наслови, истакнати цитати, линкови, листи, фото галерија — сè што треба за да се претстави бренд или производ.</td>
        </tr>
        <tr>
            <td class="check">✓</td>
            <td class="label">Уникатен QR код</td>
            <td class="desc">Сопствен QR код за секоја презентација — лесно за печатење на штанд, постер или роли-ап.</td>
        </tr>
    </table>

    <div class="hl-bar">
        <div class="hl-bar-title">Идеално за</div>
        <div class="hl-bar-text">
            Лансирање на нов производ · претставување на услуга · промоција на саем
            или конгрес · каталог на производи · информативен материјал за пациенти
            и клиенти на здравствени услуги · контакт хаб за поврзување.
        </div>
    </div>

    <div class="pg-footer">
        <table>
            <tr>
                <td>Global Net ADV — Дигитални услуги за настани</td>
                <td class="right">Стр. 5</td>
            </tr>
        </table>
    </div>
</div>

{{-- ════════ PAGE 6: CONTACT ════════ --}}
<div class="page contact">
    <div class="contact-eyebrow">КОНТАКТИРАЈТЕ НÈ</div>
    <div class="contact-title">Подгответе се за<br>вашиот следен настан.</div>
    <div class="contact-divider"></div>
    <div class="contact-lead">
        Закажете кратка средба или повик. Ќе ви покажеме примери, ќе одговориме на
        прашања и ќе подготвиме понуда прилагодена на вашите потреби — без обврска.
    </div>

    <table class="contact-info">
        <tr>
            <td class="ci-lbl">Телефон</td>
            <td class="ci-val">071 317 377</td>
        </tr>
        <tr>
            <td class="ci-lbl">Е-пошта</td>
            <td class="ci-val">globalnetadv@globalnetadv.mk</td>
        </tr>
        <tr>
            <td class="ci-lbl">Веб</td>
            <td class="ci-val">globalnetadv.mk</td>
        </tr>
    </table>

    <div class="contact-cta">
        <div class="contact-cta-title">Global Net ADV</div>
        <div class="contact-cta-text">
            Агенција за маркетинг и адвертајзинг · 30+ години искуство · Скопје
        </div>
    </div>
</div>

</body>
</html>
