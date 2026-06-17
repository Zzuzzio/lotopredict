#!/usr/bin/env python3
"""Train evolutionary neural network on exported lottery dataset."""

from __future__ import print_function

import argparse
import json
import os
import sys

from lottery_nn import load_draws_from_dataset, train_evolution


def main():
    parser = argparse.ArgumentParser(description='Train evolutionary NN for lottery')
    parser.add_argument('--dataset', required=True, help='Path to JSON dataset')
    parser.add_argument('--out', default=None, help='Output model path')
    parser.add_argument('--window', type=int, default=10)
    parser.add_argument('--hidden', type=int, default=24)
    parser.add_argument('--population', type=int, default=50)
    parser.add_argument('--generations', type=int, default=120)
    args = parser.parse_args()

    if not os.path.isfile(args.dataset):
        print('Dataset not found: ' + args.dataset, file=sys.stderr)
        sys.exit(1)

    meta, draws = load_draws_from_dataset(args.dataset)
    print('Dataset: {} draws ({}-{})'.format(
        len(draws),
        draws[0]['draw_number'] if draws else '-',
        draws[-1]['draw_number'] if draws else '-',
    ))

    net, metrics = train_evolution(
        draws,
        window=args.window,
        hidden=args.hidden,
        population=args.population,
        generations=args.generations,
        main_max=meta['main_max'],
        bonus_max=meta['bonus_max'],
        main_count=meta['main_count'],
    )

    model = {
        'version': 1,
        'lottery': meta['lottery'],
        'window': args.window,
        'hidden': args.hidden,
        'main_max': meta['main_max'],
        'bonus_max': meta['bonus_max'],
        'main_count': meta['main_count'],
        'weights': net.weights.tolist(),
        'metrics': metrics,
        'trained_at': __import__('datetime').datetime.utcnow().isoformat() + 'Z',
    }

    out = args.out
    if out is None:
        base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        out = os.path.join(base, 'ml', 'models', meta['lottery'] + '.json')

    out_dir = os.path.dirname(out)
    if not os.path.isdir(out_dir):
        os.makedirs(out_dir)

    with open(out, 'w', encoding='utf-8') as f:
        json.dump(model, f)

    print('Model saved: ' + out)
    print(json.dumps(metrics, indent=2))


if __name__ == '__main__':
    main()
