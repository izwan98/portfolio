<div class="lane-cols">
    <template x-for="col in advice.byLane" :key="col.lane">
        <section class="lane-col">
            <h4 class="lane-col-head"><span x-text="laneLabel[col.lane]"></span></h4>
            <ol class="lane-picks">
                <template x-for="(r, i) in col.picks" :key="r.id">
                    <li class="lane-pick">
                        <button type="button" class="adv-hero" @click="choose(r.id)" :disabled="!d.active || d.active.kind !== 'pick' || d.active.team !== 'ally'" :title="'Lock in ' + hero(r.id)?.name">
                            <span class="adv-no" x-text="i + 1"></span>
                            <img :src="img(r.id, 80)" :alt="hero(r.id)?.name">
                        </button>
                        <div class="adv-text">
                            <div class="adv-name">
                                <b x-text="hero(r.id)?.name"></b>
                                <span class="adv-wr" :class="wrClass(r.id)" x-text="wr(r.id)"></span>
                            </div>
                            <ul class="reasons">
                                <template x-for="(why, j) in r.reasons.filter(x => !x.text.startsWith('Fills')).slice(0, 3)" :key="j"><li :class="why.kind" x-text="why.text"></li></template>
                            </ul>
                        </div>
                    </li>
                </template>
                <li class="muted small" x-show="!col.picks.length">No open heroes for this lane.</li>
            </ol>
        </section>
    </template>
</div>
