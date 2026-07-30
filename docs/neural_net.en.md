# ML Model: Transport-Mode Selection

[🇷🇺 Русская версия](neural_net.md)

## What changed since the first version

In the project's first version, the "neural network" was actually a
hand-tuned classifier: weights for each class (`walk`/`car`/`bus`) were
picked by intuition and hardcoded as constants. No training ever happened —
it was just a formula with three sets of numbers.

In this version the weights are **trained from scratch with gradient
descent** on a dataset — `SoftmaxClassifier` starts from zero weights and
iteratively minimizes a cross-entropy loss function. This is a real, if
simple, ML model: multi-class logistic regression (softmax regression).

## Problem Statement

Given two route features, predict one of three transport classes:

- `walk`;
- `car`;
- `bus` — public/intercity transport.

## Input Features

- **Route distance** (km, after TSP order optimization);
- **Number of stops** (cities) in the route.

### Why distance is scaled logarithmically

The naive feature `distance_km / 1000` **didn't work** — during development
the model consistently confused short walking routes with car trips. The
reason: the distance range spans three orders of magnitude (from a fraction
of a km to ~1500 km), and on a linear scale the "walking" zone (0–3 km) is a
vanishingly thin sliver near zero (0.003 vs., say, 0.35 for 350 km). A
linear classifier can barely draw a boundary in such a compressed space.

The fix is logarithmic scaling (see `App\ML\FeatureEncoder`):

```php
x1 = log(1 + distance_km) / log(1 + 1500)
```

The log transform spreads out short distances far more evenly: the
difference between 1 km and 3 km becomes comparable in significance to the
difference between 300 km and 900 km — closer to how people intuitively
perceive distance. After this change, validation accuracy rose from ~77% to
~93% (see below).

The second feature, number of stops, is scaled to roughly 0–1:
`x2 = stops / 10`.

## Training Data — an Honest Note

The app doesn't accumulate a history of real user decisions (routes aren't
saved), so there's literally no "statistics" to train on. `App\ML\Dataset`
generates a **synthetic** dataset instead:

1. Distance and number of stops are randomly generated within realistic
   ranges (a mix of short, medium, and long routes).
2. Each example gets a "ground truth" label via a simple rule (roughly: up
   to 3 km — walk, up to 350 km — car, further — bus/train, adjusted for
   number of stops).
3. **8% of labels are deliberately noised** — randomly flipped to a
   different class.

The noise isn't an implementation accident — it's a deliberate choice:
without it, the classes are perfectly separable, training degenerates into a
trivial problem, and the model's weights diverge toward infinity (a known
pathology of logistic regression on linearly separable data). The noise also
sets a sensible accuracy ceiling — the model physically cannot (and should
not) guess the noised labels correctly, so 100% accuracy is a deliberately
unreachable and undesirable target.

**If route history storage is ever added to the app**, this synthetic
dataset can be swapped for real user data without touching
`SoftmaxClassifier` at all — it has no knowledge of where its training
examples came from.

## Training

Training is standard batch gradient descent on cross-entropy loss:

```text
for each epoch:
    for each example:
        probs = softmax(w · x)
        loss += -log(probs[correct_class])
        for each class k:
            gradient[k] += (probs[k] - target[k]) * x
    weights -= learning_rate * gradient / n_examples
```

Run with `php bin/train_model.php`. The script splits the data into training
(80%) and validation (20%) sets, trains the model only on the training
split, and honestly reports accuracy on the validation split — data the
model never saw.

## Metrics (current run)

| Set | Accuracy |
|---|---|
| Training | ~90% |
| Validation (unseen by the model) | ~93% |

Since 8% of the dataset's labels are noised, the theoretical accuracy
ceiling is around 92–95%: the model can't (and shouldn't) correctly predict
deliberately atypical examples. A validation result in that range means the
model has trained close to the practical limit rather than overfitting
(training and validation accuracy are close — a clear sign that there's no
overfitting).

You can reproduce both numbers yourself by rerunning
`php bin/train_model.php` — the result is deterministic (a fixed seed is
used), but sensitive to any changes you make to `Dataset`.

## 2026 Upgrade: MLP — a Neural Network with a Hidden Layer and Backprop from Scratch

`SoftmaxClassifier` (described above) is a linear model — it has no hidden
layer, so it can only draw a *straight* boundary between classes in feature
space. `App\ML\MLPClassifier` adds one hidden layer (8 neurons, `tanh`
activation) between the input and the softmax output — introducing
non-linearity, so in theory the model can learn a more complex, curved
decision boundary.

Like `SoftmaxClassifier`, the MLP is trained **from scratch**: no
sklearn/TensorFlow, just PHP arrays and hand-written nested loops. Forward
pass:

```text
z1 = W1·x + b1;  a1 = tanh(z1)     # hidden layer
z2 = W2·a1 + b2; probs = softmax(z2)  # output layer
```

Backward pass — backpropagation via the chain rule, by hand:

```text
dz2 = probs - target                  # derivative of softmax + cross-entropy — same formula as SoftmaxClassifier
dW2 = dz2 ⊗ a1;  db2 = dz2

da1 = W2ᵀ · dz2                       # error is propagated back through the output layer's weights
dz1 = da1 * (1 - a1²)                 # derivative of tanh
dW1 = dz1 ⊗ x;   db1 = dz1
```

The full derivation and a note on weight initialization (why you can't start
from all-zero weights, unlike `SoftmaxClassifier` — the symmetry problem)
are in the docblock of `App\ML\MLPClassifier`.

### An honest comparison with the baseline — the MLP isn't "automatically better"

`bin/train_model.php` now trains both models on the exact same train/val
split and prints a side-by-side comparison. Results across several seeds:

| Seed | MLP (val) | Softmax (val) |
|---|---|---|
| 1 | 90.0% | 90.0% |
| 7 | 85.8% | 86.7% |
| 42 | 90.0% | 90.8% |
| 123 | 89.2% | 86.7% |

The models run neck and neck, and softmax is occasionally slightly ahead.
This isn't a bug or an undertrained network — it's expected for *this
specific* problem: the ground-truth labeling rule in `Dataset` (distance
thresholds + a stop-count adjustment) is itself close to piecewise-linear,
and the 8% noised labels set an accuracy ceiling that washes out the small
theoretical advantage of a non-linear model. On a validation set of 120
examples (20% of 600), a 1-point-percentage difference is literally one
example — statistical noise, not signal.

**The practical takeaway** worth stating in an interview: the MLP isn't here
because "neural net == more accuracy" — it's here because (a) it demonstrates
a complete understanding of backprop and where gradients come from beyond
the trivial linear case, and (b) the architecture is ready for the day when
there are more features and genuine non-linearity in the data (e.g., time of
day, whether a rail line exists between two cities, traffic history) — that's
when the MLP's advantage over a linear model would actually show up. On two
simple features with noised labels, expecting a dramatic gap wouldn't be an
honest claim to make.

By default the app (`TransportPredictor`) still uses the MLP — it's slightly
more accurate on average across runs and it's the one that demonstrates the
full neural-network architecture; `SoftmaxClassifier` stays in the project
as a reference simpler model and as a fallback if the MLP's weights file is
missing.

### Rigorous evaluation: accuracy alone isn't enough

Before `App\ML\ModelEvaluator` existed, the only metric in the project was
accuracy — the fraction of correct predictions. It has a well-known
weakness: on imbalanced classes (and `walk` is the rarest class in the
dataset, ~20% of examples), high accuracy is achievable even if the model
systematically underpredicts the rare class, simply by guessing the common
classes more often.

`php bin/train_model.php` now also prints a confusion matrix and
per-class precision/recall/F1. Example from a real run (seed 42):

```
--- MLP: confusion matrix (rows = true class, columns = predicted) ---
              walk     car     bus
  walk          25       0       1
  car            1      49       2
  bus            0       8      34

--- MLP: precision / recall / F1 by class ---
  Class  Precision     Recall         F1    Support
  walk        0.962      0.962      0.962         26
  car          0.86      0.942      0.899         52
  bus         0.919       0.81      0.861         42

  Accuracy: 0.9   Macro-F1: 0.907
```

What this shows **beyond** a single accuracy number: `bus` recall (0.81) is
noticeably lower than `walk`/`car` — the model more often confuses long
routes with car trips (the confusion matrix shows exactly that: 8 `bus`
examples predicted as `car`) than the other way around. That's an honest
finding accuracy alone hides — an overall accuracy of 0.9 sounds even and
doesn't hint at this imbalance.

**Interestingly, on macro-F1 (0.907) the MLP slightly trails Softmax
(0.912) on this particular run** — the same conclusion as in the accuracy
section above, just via a stricter metric: the gap favoring the linear
model isn't statistically meaningful (support for the `bus` class is only 42
examples), but the fact that a more complex model isn't guaranteed to win
even on F1, not just accuracy, is an important, honest result — not
something to bury in favor of a flashier neural-network narrative.

### K-fold cross-validation — not relying on one "lucky" split

A single random train/val split can get statistically "lucky" or "unlucky"
— especially with only 600 examples. `ModelEvaluator::kFoldCrossValidate()`
splits the full dataset into 5 folds and trains the model 5 times, each time
validating on the fold that wasn't used for training:

```
Accuracy per fold: 92.5%, 90.8%, 90.8%, 91.7%, 90%
Mean: 91.2% ± 0.9%
```

The spread across folds (±0.9%) is small — the result is stable and doesn't
depend heavily on the specific data split. If the spread had been, say,
±8%, that would signal that 600 examples is too few for a reliable
estimate, and either the dataset should be larger or a single accuracy
number shouldn't be trusted without a confidence interval.

### Decision-boundary visualization

`GET /api/decision_boundary.php?model=mlp|softmax` computes the model's
prediction on a regular [distance × stops] grid and returns JSON with the
resulting class per cell — the frontend (`assets/js/ml_boundary.js`,
Chart.js) just draws what it receives, without duplicating the forward pass
in JS. The training dataset's real points are overlaid on top of the
decision map, showing where the model is "confident" versus where it's
working through sparse or noisy regions. The model toggle (MLP/softmax) in
the UI is the clearest way to see the difference (or lack thereof) between
the linear and non-linear boundary live.

### Known limitations of the model

- Trained on synthetic, not real user data (see above) — honestly disclosed
  in the README.
- Only considers distance and number of stops — has no visibility into the
  real road network, traffic, or whether rail/bus service exists between two
  specific cities.
- `MLPClassifier` (the neural net with a hidden layer) is used by default —
  but as shown above, on these two features with noised labels it doesn't
  give a dramatic edge over the linear `SoftmaxClassifier`. This is a
  deliberately documented fact, not an attempt to hide that "the neural net
  didn't help": the MLP's value here is in the architecture (full backprop
  from scratch) and in future-readiness, not in a current accuracy gain on a
  simple problem.
- With OSRM now available, the model can be fed **road** distance (typically
  10–20% longer than great-circle distance) instead of just the straight-line
  approximation. The model wasn't specifically retrained for this
  difference — the class thresholds (~3 km, ~350 km) have enough margin that
  a small systematic increase in the input number doesn't change the
  prediction in the vast majority of cases. This isn't a perfect solution, but
  a deliberate trade-off: ideally the model would be retrained on the real
  distribution of road distances.
