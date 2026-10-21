<ol class="adv-list">
    <template x-for="(r, i) in {{ $list }}" :key="r.id">
        <li class="adv-item">
            <button type="button" class="adv-hero" @click="choose(r.id)" :disabled="!d.active" :title="d.active ? 'Put ' + hero(r.id)?.name + ' in the active slot' : ''">
                <span class="adv-no" x-text="i + 1"></span>
                <img :src="img(r.id, 80)" :alt="hero(r.id)?.name">
            </button>
            <div class="adv-text">
                <div class="adv-name">
                    <b x-text="hero(r.id)?.name"></b>
                    <span class="lane-chip" x-text="hero(r.id)?.lanes.map(l => laneLabel[l]).join('/')"></span>
                    <span class="adv-wr" :class="wrClass(r.id)" x-text="wr(r.id)"></span>
                </div>
                <ul class="reasons">
                    @if ($mode === 'pick' || $mode === 'plan')
                        <template x-for="(why, j) in r.reasons.slice(0, 3)" :key="j"><li :class="why.kind" x-text="why.text"></li></template>
                    @else
                        <template x-for="(why, j) in r.reasons.slice(0, 2)" :key="j"><li x-text="why"></li></template>
                    @endif
                </ul>
            </div>
        </li>
    </template>
</ol>
