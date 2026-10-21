{{-- ================================================================ library --}}
<div class="modal" x-show="showTemplates" x-transition.opacity x-cloak @keydown.escape.window="showTemplates = false" @click.self="showTemplates = false">
    <div class="modal-card modal-wide" role="dialog" aria-label="Pick and ban templates">
        <div class="modal-head">
            <h3>Pick &amp; ban templates</h3>
            <div class="modal-actions">
                <button type="button" class="btn btn-gold" @click="openEditor()">+ New template</button>
                <button type="button" class="btn" @click="showTemplates = false">Close</button>
            </div>
        </div>
        <p class="muted">A template is the exact order of every ban and pick turn, per team and player. Templates you save are shared with everyone who uses MLBB Draft.</p>
        <input type="search" x-model="templateQuery" placeholder="Search templates…" aria-label="Search templates">

        <div class="tpl-list">
            <template x-for="t in filteredTemplates" :key="t.id">
                <article class="tpl" :class="template.id === t.id && 'in-use'">
                    <div class="tpl-head">
                        <div>
                            <h4 x-text="t.name"></h4>
                            <p class="tpl-meta">
                                <span class="tpl-badge" :class="t.builtIn ? 'official' : 'community'" x-text="t.builtIn ? 'Built-in' : 'Community'"></span>
                                <span x-show="t.author" x-text="'by ' + t.author"></span>
                                <span x-text="templateSummary(t)"></span>
                                <span class="tpl-badge mine" x-show="canEdit(t)">Yours</span>
                            </p>
                        </div>
                        <div class="tpl-actions">
                            <button type="button" class="btn btn-gold" @click="useTemplate(t.id)" :disabled="template.id === t.id" x-text="template.id === t.id ? 'In use' : 'Use'"></button>
                            <button type="button" class="btn" @click="openEditor(t)" title="Start a new template from this one">Duplicate</button>
                            <button type="button" class="btn" x-show="canEdit(t)" @click="openEditor(t, true)">Edit</button>
                            <button type="button" class="btn" x-show="!t.builtIn" @click="copyLink(t)" x-text="copiedLink === t.id ? 'Copied!' : 'Share link'"></button>
                        </div>
                    </div>
                    <p class="tpl-desc" x-show="t.description" x-text="t.description"></p>
                    <ol class="timeline">
                        <template x-for="(s, i) in t.steps" :key="i">
                            <li :class="['tl-' + s.action, 'tl-' + s.team, s.newRound && 'tl-break']" :title="(s.team === 'first' ? 'First' : 'Second') + ' team · player ' + s.player + ' ' + s.action + 's'" x-text="(s.action === 'ban' ? 'B' : 'P') + s.player"></li>
                        </template>
                    </ol>
                </article>
            </template>
            <p class="muted" x-show="!filteredTemplates.length">No templates match.</p>
        </div>
    </div>
</div>

{{-- ================================================================= editor --}}
<div class="modal" x-show="ed.open" x-transition.opacity x-cloak @keydown.escape.window="ed.open = false" @click.self="ed.open = false">
    <div class="modal-card modal-wide editor" role="dialog" :aria-label="ed.id ? 'Edit template' : 'New template'">
        <div class="modal-head">
            <h3 x-text="ed.id ? 'Edit template' : 'New template'"></h3>
            <button type="button" class="btn" @click="ed.open = false">Cancel</button>
        </div>

        <div class="ed-fields">
            <label class="field"><span>Name</span><input type="text" x-model="ed.name" maxlength="60" placeholder="e.g. Scrim — 3 bans each, alternate"></label>
            <label class="field"><span>Your name <small>(optional)</small></span><input type="text" x-model="ed.author" maxlength="40" placeholder="Shown on the template"></label>
            <label class="field field-wide"><span>Description <small>(optional)</small></span><input type="text" x-model="ed.description" maxlength="200" placeholder="When to use it"></label>
            <label class="check-field">
                <input type="checkbox" x-model="ed.blindBans">
                <span><b>Blind bans</b> — teams ban at the same time, so both may ban the same hero in a ban round.</span>
            </label>
        </div>

        <div class="ed-tools">
            <span class="setup-label">Add turn</span>
            <button type="button" class="btn btn-ban" @click="edAdd('ban', 'first')">+ Ban · 1st team</button>
            <button type="button" class="btn btn-ban" @click="edAdd('ban', 'second')">+ Ban · 2nd team</button>
            <button type="button" class="btn btn-pick" @click="edAdd('pick', 'first')">+ Pick · 1st team</button>
            <button type="button" class="btn btn-pick" @click="edAdd('pick', 'second')">+ Pick · 2nd team</button>
            <select @change="edQuick($event.target.value); $event.target.value = ''" aria-label="Quick add">
                <option value="">Quick add…</option>
                <option value="bans-3-snake">3 bans each · snake (A B B A A B)</option>
                <option value="bans-3-alt">3 bans each · alternate (A B A B A B)</option>
                <option value="bans-2-snake">2 bans each · snake</option>
                <option value="bans-2-alt-second">2 bans each · alternate, 2nd team first</option>
                <option value="snake-picks">5 picks each · 1-2-2-2-2-1</option>
                <option value="picks-3-snake">3 picks each · snake</option>
                <option value="picks-2-snake-second">2 picks each · snake, 2nd team first</option>
            </select>
            <button type="button" class="link" x-show="ed.steps.length" @click="ed.steps = []">Clear all</button>
        </div>

        <ol class="ed-steps">
            <template x-for="(s, i) in ed.steps" :key="i">
                <li class="ed-step" :class="['tl-' + s.action, 'tl-' + s.team]">
                    <span class="ed-no" x-text="i + 1"></span>
                    <div class="seg seg-sm">
                        <button type="button" :class="s.action === 'ban' && 'on'" @click="s.action = 'ban'">Ban</button>
                        <button type="button" :class="s.action === 'pick' && 'on'" @click="s.action = 'pick'">Pick</button>
                    </div>
                    <div class="seg seg-sm">
                        <button type="button" :class="s.team === 'first' && 'on'" @click="s.team = 'first'">1st team</button>
                        <button type="button" :class="s.team === 'second' && 'on'" @click="s.team = 'second'">2nd team</button>
                    </div>
                    <select x-model.number="s.player" aria-label="Player">
                        <template x-for="n in [1,2,3,4,5]" :key="n"><option :value="n" x-text="'Player ' + n" :selected="s.player === n"></option></template>
                    </select>
                    <label class="round-toggle" x-show="s.action === 'ban' && i > 0 && ed.steps[i - 1].action === 'ban'" title="Bans before this one are revealed first; with blind bans, duplicates only count within a round">
                        <input type="checkbox" x-model="s.newRound"> New ban round
                    </label>
                    <span class="ed-move">
                        <button type="button" class="icon" @click="edMove(i, -1)" :disabled="i === 0" aria-label="Move up">↑</button>
                        <button type="button" class="icon" @click="edMove(i, 1)" :disabled="i === ed.steps.length - 1" aria-label="Move down">↓</button>
                        <button type="button" class="icon danger" @click="edRemove(i)" aria-label="Remove turn">✕</button>
                    </span>
                </li>
            </template>
            <li class="ed-empty" x-show="!ed.steps.length">No turns yet — add bans and picks above, or use “Quick add”.</li>
        </ol>

        <div class="ed-preview" x-show="ed.steps.length">
            <span class="setup-label">Preview</span>
            <ol class="timeline">
                <template x-for="(s, i) in ed.steps" :key="i">
                    <li :class="['tl-' + s.action, 'tl-' + s.team, s.newRound && 'tl-break']" x-text="(s.action === 'ban' ? 'B' : 'P') + s.player"></li>
                </template>
            </ol>
            <p class="legend"><i class="tl-first"></i> 1st team (also bans/picks first in the draft) <i class="tl-second"></i> 2nd team · B = ban, P = pick, number = player · a gap marks a new ban round</p>
        </div>

        <ul class="ed-problems" x-show="edProblems.length">
            <template x-for="(p, i) in edProblems" :key="i"><li x-text="p"></li></template>
        </ul>
        <p class="ed-error" x-show="ed.error" x-text="ed.error"></p>

        <div class="modal-foot">
            <button type="button" class="btn btn-danger" x-show="ed.id" @click="deleteTemplate(templates.find(t => t.id === ed.id))">Delete template</button>
            <span class="muted small" x-show="!ed.id">Shared with everyone. Only this browser can edit or delete it later.</span>
            <button type="button" class="btn btn-gold" @click="saveTemplate()" :disabled="edProblems.length || ed.name.trim().length < 3 || ed.saving" x-text="ed.saving ? 'Saving…' : ed.id ? 'Save changes' : 'Save & share'"></button>
        </div>
    </div>
</div>
