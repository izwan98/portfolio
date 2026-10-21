<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#120f2e">
    <meta name="description" content="Tung Tung Tung Sahur: a rhythm game. Hit the tungs, land the SAHUR, wake up the whole kampung.">

    <title>Tung Tung Tung Sahur · Rhythm Wake-Up Call</title>
    <link rel="icon" href="{{ asset('images/triple-t/tung.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bungee&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/triple-t.css', 'resources/js/triple-t/main.js'])
</head>
<body>

    {{-- Night sky over the kampung --}}
    <div class="sky" aria-hidden="true">
        <div id="stars"></div>
        <div class="moon"></div>
        <svg id="kampung" viewBox="0 0 1200 260" preserveAspectRatio="xMidYMax slice"></svg>
    </div>

    <div class="stage">
        {{-- The man himself --}}
        <section class="char-col" aria-hidden="true">
            <div id="bubble" class="bubble" data-mood="tung">TUNG!</div>
            <img id="tung" class="tung" src="{{ asset('images/triple-t/tung.png') }}" alt="">
            <div class="shadow"></div>
        </section>

        {{-- Note highway --}}
        <section class="lane-col">
            <canvas id="highway" aria-label="Note highway. Use D F J K, or tap the lanes."></canvas>
            <div id="count" class="count"></div>
            <div id="judge" class="judge"></div>
            <div id="combo" class="combo"><b>0</b><small>combo</small></div>
        </section>

        {{-- HUD --}}
        <aside class="hud">
            <div class="hud-card">
                <span class="hud-label">Score</span>
                <span id="hud-score" class="hud-score">0</span>
                <span id="hud-mult" class="hud-mult">×1</span>
            </div>

            <div class="hud-card">
                <span class="hud-label">Village awake</span>
                <div id="hud-health" class="health"><i></i></div>
            </div>

            <div class="hud-grid">
                <div class="hud-card">
                    <span class="hud-label">Imsak in</span>
                    <span id="hud-clock" class="hud-value">0:00</span>
                    <div id="hud-progress" class="progress"><i></i></div>
                </div>
                <div class="hud-card">
                    <span class="hud-label">Accuracy</span>
                    <span id="hud-acc" class="hud-value">100%</span>
                </div>
                <div class="hud-card">
                    <span class="hud-label">Houses woken</span>
                    <span id="hud-woken" class="hud-value">0/0</span>
                </div>
                <button id="btn-pause" type="button" class="hud-card hud-pause" aria-label="Pause">
                    <svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                    <span>Pause</span>
                </button>
            </div>
        </aside>
    </div>

    {{-- Title --}}
    <section id="title-screen" class="screen title-screen">
        <div class="title-inner">
            <div class="title-art" aria-hidden="true">
                <img src="{{ asset('images/triple-t/tung.png') }}" alt="">
            </div>

            <div class="title-copy">
                <p class="kicker">The Rhythm Wake-Up Call</p>
                <h1 class="logo">
                    <span class="logo-tung">Tung</span>
                    <span class="logo-tung">Tung</span>
                    <span class="logo-tung">Tung</span>
                    <span class="logo-sahur">Sahur!</span>
                </h1>
                <p class="lede">It's 3 AM. Hit the <b class="t-wood">tungs</b> on the beat and land every <b class="t-gold">SAHUR</b> to wake the whole kampung before imsak.</p>

                <div class="modes">
                    @foreach (['easy' => ['Easy', 96, 'A gentle knock on the door.'], 'normal' => ['Normal', 116, 'The whole street hears you.'], 'sahur' => ['Sahur Mode', 140, 'Imsak is in five minutes. GO.']] as $key => [$label, $bpm, $blurb])
                        <button type="button" class="mode mode-{{ $key }}" data-start="{{ $key }}" @if ($loop->first) data-autofocus @endif>
                            <span class="mode-name">{{ $label }}</span>
                            <span class="mode-bpm">{{ $bpm }} BPM</span>
                            <span class="mode-blurb">{{ $blurb }}</span>
                            <span class="mode-best" data-best="{{ $key }}"></span>
                        </button>
                    @endforeach
                </div>

                <div class="controls">
                    <div class="keys">
                        @foreach (['D', 'F', 'J', 'K'] as $k)
                            <kbd class="key key-{{ $loop->index }}">{{ $k }}</kbd>
                        @endforeach
                        <span>or tap the lanes</span>
                    </div>
                    <p><b class="t-gold">SAHUR</b> notes: hit <kbd>F</kbd> or <kbd>J</kbd> (or <kbd>Space</kbd>). <kbd>Esc</kbd> pauses.</p>
                </div>

                <div class="options">
                    <label>Voice <button id="opt-voice" type="button" class="opt">On</button></label>
                    <label>Note speed <button id="opt-speed" type="button" class="opt">1×</button></label>
                    <label>Audio offset
                        <span class="opt-group">
                            <button type="button" class="opt" data-offset="-10" aria-label="Decrease offset">−</button>
                            <span id="opt-offset" class="opt-value">0 ms</span>
                            <button type="button" class="opt" data-offset="10" aria-label="Increase offset">+</button>
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </section>

    {{-- Pause --}}
    <section id="pause-screen" class="screen modal-screen" hidden>
        <div class="modal">
            <h2>Paused</h2>
            <p>The kampung is waiting…</p>
            <div class="modal-actions">
                <button id="btn-resume" type="button" class="btn btn-gold" data-autofocus>Resume</button>
                <button id="btn-restart" type="button" class="btn">Restart</button>
                <button id="btn-quit" type="button" class="btn">Quit</button>
            </div>
        </div>
    </section>

    {{-- Results --}}
    <section id="results-screen" class="screen modal-screen" hidden>
        <div class="modal results">
            <div id="res-grade" class="grade">S</div>
            <h2 id="res-title">Everyone is awake!</h2>
            <p id="res-sub" class="muted"></p>

            <p class="res-score"><span id="res-score">0</span> <em id="res-best" hidden>New best!</em></p>

            <dl class="res-stats">
                <div><dt>Accuracy</dt><dd id="res-acc">0%</dd></div>
                <div><dt>Max combo</dt><dd id="res-combo">0</dd></div>
                <div><dt>Houses woken</dt><dd id="res-woken">0</dd></div>
            </dl>

            <dl class="res-judges">
                <div class="j-perfect"><dt>Perfect</dt><dd id="res-perfect">0</dd></div>
                <div class="j-great"><dt>Great</dt><dd id="res-great">0</dd></div>
                <div class="j-good"><dt>Good</dt><dd id="res-good">0</dd></div>
                <div class="j-miss"><dt>Miss</dt><dd id="res-miss">0</dd></div>
            </dl>

            <div class="modal-actions">
                <button id="btn-retry" type="button" class="btn btn-gold" data-autofocus>Play again</button>
                <button id="btn-menu" type="button" class="btn">Menu</button>
            </div>
        </div>
    </section>

</body>
</html>
