"""
Evolutionary neural network for lottery prediction.
Pure Python 3.6+ — no numpy/pip required.
"""

from __future__ import division

import json
import math
import random


def encode_draw(main, bonus, main_max=36, bonus_max=4):
    vec = [0.0] * (main_max + bonus_max)
    for n in main:
        idx = int(n) - 1
        if 0 <= idx < main_max:
            vec[idx] = 1.0
    for b in bonus:
        idx = main_max + int(b) - 1
        if 0 <= idx < main_max + bonus_max:
            vec[idx] = 1.0
    return vec


def encode_window(draws, window, main_max=36, bonus_max=4):
    if len(draws) < window:
        raise ValueError('Not enough draws for window')
    parts = []
    for d in draws[-window:]:
        parts.extend(encode_draw(d['main'], d['bonus'], main_max, bonus_max))
    return parts


def _tanh_vec(v):
    return [math.tanh(x) for x in v]


def _sigmoid_vec(v):
    out = []
    for x in v:
        if x > 35:
            out.append(1.0)
        elif x < -35:
            out.append(0.0)
        else:
            out.append(1.0 / (1.0 + math.exp(-x)))
    return out


def _matvec_flat(w, rows, cols, vec, bias):
    out = [0.0] * rows
    idx = 0
    for i in range(rows):
        s = bias[i]
        for j in range(cols):
            s += w[idx] * vec[j]
            idx += 1
        out[i] = s
    return out


class EvoNetwork(object):
    def __init__(self, window, hidden, main_max, bonus_max, main_count, weights=None):
        self.window = int(window)
        self.hidden = int(hidden)
        self.main_max = int(main_max)
        self.bonus_max = int(bonus_max)
        self.main_count = int(main_count)
        self.input_size = (main_max + bonus_max) * window
        self.output_size = main_max + bonus_max
        self.w1_size = self.input_size * self.hidden
        self.b1_size = self.hidden
        self.w2_size = self.hidden * self.output_size
        self.b2_size = self.output_size
        self.total_weights = self.w1_size + self.b1_size + self.w2_size + self.b2_size
        if weights is None:
            self.weights = [random.gauss(0, 0.5) for _ in range(self.total_weights)]
        else:
            self.weights = list(weights)

    def _unpack(self):
        o = 0
        w1 = self.weights[o:o + self.w1_size]
        o += self.w1_size
        b1 = self.weights[o:o + self.b1_size]
        o += self.b1_size
        w2 = self.weights[o:o + self.w2_size]
        o += self.w2_size
        b2 = self.weights[o:o + self.b2_size]
        return w1, b1, w2, b2

    def forward(self, x, noise=0.0):
        w1, b1, w2, b2 = self._unpack()
        h = _tanh_vec(_matvec_flat(w1, self.hidden, self.input_size, x, b1))
        out = _sigmoid_vec(_matvec_flat(w2, self.output_size, self.hidden, h, b2))
        if noise > 0:
            out = [min(1.0, max(0.0, v + random.gauss(0, noise))) for v in out]
        return out

    def predict(self, draws, noise=0.0):
        x = encode_window(draws, self.window, self.main_max, self.bonus_max)
        out = self.forward(x, noise=noise)
        main_scores = out[:self.main_max]
        bonus_scores = out[self.main_max:]

        indexed = sorted(enumerate(main_scores), key=lambda t: -t[1])
        main = []
        for idx, _ in indexed:
            num = idx + 1
            if num not in main:
                main.append(num)
            if len(main) >= self.main_count:
                break

        bonus_idx = 0
        best = bonus_scores[0]
        for i, v in enumerate(bonus_scores):
            if v > best:
                best = v
                bonus_idx = i

        main.sort()
        return main, [bonus_idx + 1]

    def to_dict(self):
        return {
            'window': self.window,
            'hidden': self.hidden,
            'main_max': self.main_max,
            'bonus_max': self.bonus_max,
            'main_count': self.main_count,
            'weights': self.weights,
        }

    @classmethod
    def from_dict(cls, data):
        return cls(
            data['window'],
            data['hidden'],
            data['main_max'],
            data['bonus_max'],
            data['main_count'],
            weights=data['weights'],
        )


def score_draw(pred_main, pred_bonus, actual_main, actual_bonus):
    hits = len(set(pred_main) & set(actual_main))
    bonus_hit = 1.0 if pred_bonus and actual_bonus and pred_bonus[0] == actual_bonus[0] else 0.0
    return hits + bonus_hit


def evaluate_network(net, draws, indices):
    if not indices:
        return 0.0
    total = 0.0
    count = 0
    for i in indices:
        if i < net.window:
            continue
        window_draws = draws[i - net.window:i]
        actual = draws[i]
        pred_main, pred_bonus = net.predict(window_draws)
        total += score_draw(pred_main, pred_bonus, actual['main'], actual['bonus'])
        count += 1
    return total / max(count, 1)


def train_evolution(draws, window=10, hidden=24, population=50, generations=120,
                    main_max=36, bonus_max=4, main_count=5, val_ratio=0.2, seed=42):
    random.seed(seed)

    n = len(draws)
    if n < window + 20:
        raise ValueError('Need at least {} draws, got {}'.format(window + 20, n))

    split = int(n * (1.0 - val_ratio))
    train_idx = list(range(window, split))
    val_idx = list(range(max(split, window), n))

    population_list = [EvoNetwork(window, hidden, main_max, bonus_max, main_count) for _ in range(population)]

    best_net = None
    best_val = -1.0
    history = []

    for gen in range(generations):
        scored = []
        for net in population_list:
            fit = evaluate_network(net, draws, train_idx)
            scored.append((fit, net))

        scored.sort(key=lambda x: -x[0])
        top = [net for _, net in scored[:max(5, population // 5)]]

        gen_best = scored[0][0]
        val_fit = evaluate_network(scored[0][1], draws, val_idx)
        history.append({'gen': gen, 'train': gen_best, 'val': val_fit})

        if val_fit > best_val:
            best_val = val_fit
            best_net = EvoNetwork(window, hidden, main_max, bonus_max, main_count,
                                  weights=list(scored[0][1].weights))

        new_pop = []
        for elite in top[:3]:
            new_pop.append(EvoNetwork(window, hidden, main_max, bonus_max, main_count,
                                      weights=list(elite.weights)))

        while len(new_pop) < population:
            p1, p2 = random.sample(top, 2)
            child_w = []
            for a, b in zip(p1.weights, p2.weights):
                v = a if random.random() < 0.5 else b
                if random.random() < 0.12:
                    v += random.gauss(0, 0.35)
                child_w.append(v)
            new_pop.append(EvoNetwork(window, hidden, main_max, bonus_max, main_count, weights=child_w))

        population_list = new_pop

        if gen % 20 == 0 or gen == generations - 1:
            print('[gen {:3d}] train={:.3f} val={:.3f} best_val={:.3f}'.format(
                gen, gen_best, val_fit, best_val))

    random_baseline = main_count * main_count / float(main_max)
    metrics = {
        'train_fitness': evaluate_network(best_net, draws, train_idx),
        'val_fitness': best_val,
        'random_baseline_main': round(random_baseline, 3),
        'generations': generations,
        'population': population,
        'train_samples': len(train_idx),
        'val_samples': len(val_idx),
        'history': history[-10:],
    }
    return best_net, metrics


def load_draws_from_dataset(path):
    with open(path, 'r', encoding='utf-8') as f:
        data = json.load(f)
    return data, data['draws']
