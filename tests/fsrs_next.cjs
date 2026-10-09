// Calcula el próximo estado con ts-fsrs igual que el navegador. Uso: node fsrs_next.cjs '<card json>' <rating> [nowISO]
const FSRS = require('../public/assets/vendor/ts-fsrs/ts-fsrs.umd.js');
const c = JSON.parse(process.argv[2]);
const rating = parseInt(process.argv[3], 10);
const now = process.argv[4] ? new Date(process.argv[4]) : new Date();
const f = FSRS.fsrs(FSRS.generatorParameters({ enable_fuzz: true }));
const card = { ...c, due: new Date(c.due), last_review: c.last_review ? new Date(c.last_review) : undefined };
const n = f.next(card, now, rating).card;
console.log(JSON.stringify({
  due: new Date(n.due).toISOString(), stability: n.stability, difficulty: n.difficulty,
  elapsed_days: n.elapsed_days, scheduled_days: n.scheduled_days, learning_steps: n.learning_steps,
  reps: n.reps, lapses: n.lapses, state: n.state, last_review: n.last_review ? new Date(n.last_review).toISOString() : null
}));
