#!/usr/bin/env python3
"""Predict next draw using trained evolutionary NN."""

from __future__ import print_function

import argparse
import json
import os
import sys

from lottery_nn import EvoNetwork


def main():
    parser = argparse.ArgumentParser(description='Predict lottery numbers')
    parser.add_argument('--model', required=True, help='Path to model JSON')
    parser.add_argument('--input', required=True, help='JSON with recent draws window')
    parser.add_argument('--combinations', type=int, default=1)
    args = parser.parse_args()

    if not os.path.isfile(args.model):
        print(json.dumps({'error': 'model_not_found'}))
        sys.exit(1)

    with open(args.model, 'r', encoding='utf-8') as f:
        model = json.load(f)

    with open(args.input, 'r', encoding='utf-8') as f:
        payload = json.load(f)

    draws = payload.get('draws', [])
    window = int(model['window'])

    if len(draws) < window:
        print(json.dumps({'error': 'not_enough_draws', 'need': window, 'have': len(draws)}))
        sys.exit(1)

    net = EvoNetwork.from_dict(model)
    predictions = []

    for i in range(max(1, args.combinations)):
        noise = 0.05 * i
        main, bonus = net.predict(draws, noise=noise)
        predictions.append({'main': main, 'bonus': bonus})

    out = {
        'predictions': predictions,
        'metrics': model.get('metrics', {}),
        'model_lottery': model.get('lottery'),
    }
    print(json.dumps(out, ensure_ascii=False))


if __name__ == '__main__':
    main()
