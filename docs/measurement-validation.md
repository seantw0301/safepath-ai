# Measurement validation on real photos

2026-11-04. Phone photos (iPhone, normalised to 1920 px) checked against a tape measure. The photos stay off the repository.

**What the truth means:** door sizes are the **clear opening** (inner edge to inner edge of the door frame), which is the width a person or wheelchair passes through. The bathroom's clear height of 191 cm is measured from the top of the threshold (about 194.5 cm from the floor).

**Method:** SAM 3.1 finds the A4 sheet ("white paper") and the door ("doorway", "door", "door frame"). The scale comes from the A4's long and short sides; a sheet more than 15% off the A4 aspect ratio is rejected. A size is the bounding box divided by that scale.

## Front door: clear opening 90 cm wide, 203 cm high (A4 taped at the top middle of the door)
| Photo | A4 | Opening width (doorway box) | Width from the frame's inner edges | Height |
|---|---|---|---|---|
| 1 | rejected (51% off; wide, oblique shot) | — | — | — |
| 2 | used (12% off) | 82.2 cm (−9%) | 74.9 cm (−17%) | doorway cut off by the photo edge |
| 3 | used (8% off) | 84.2 cm (−6%) | 72.3 cm (−20%) | 162.8 cm (−20%) |
| 4 | used (15% off, at the limit) | not found | 71.0 cm (−21%) | cut off |

## Bathroom door: clear opening 65 cm wide, 191 cm high above the threshold; threshold 3.5 cm (A4 taped above the door, door open)
| Item | Estimate | Error |
|---|---|---|
| A4 | used (7% off) | — |
| Opening width (doorway box) | 71.8 cm | +10% |
| Width from the frame's inner edges | 56.0 cm | −14% |
| Height | doorway cut off by the photo edge | not usable |
| Threshold height | 3.8 cm | +0.3 cm (+9%) |

A tape-measure close-up of the threshold (no A4) is kept as evidence only.

### Retake with the new tip: A4 on the lower door leaf, phone low, facing the threshold
| Item | Result |
|---|---|
| A4 | found, confidence 0.97, **1.6% off** the A4 shape (best of all photos), 20.8 px/cm |
| "door threshold" | **not found** (also "threshold", "door sill", "raised threshold", "marble threshold") |
| "step" | found (0.70): box 137 px high → **6.6 cm** vs 3.5 cm (+88%); it touches the photo's right edge |
| Verdict with the current rules | no threshold finding at all, because the prompt list has no "step" |

Why 6.6 cm: the phone was still above the threshold, so the box covers the front face **and** the top surface. The A4 is on the door leaf while the threshold's front edge sits slightly closer to the camera; that depth difference makes the threshold look a few percent larger, small next to the top-surface problem.

What this means:
- The tip fixes the scale (1.6% off instead of 7–15%).
- Measuring a threshold needs its **front face only**: the phone at threshold height, looking straight at it. A box from above is not a height.
- Not changed yet: adding "step" as a threshold prompt would have caught it here, but as a cut-off object it would be reported "unknown", which is the right outcome.

## What we learned
- **Width:** the doorway's bounding box came within −9% to +10% of the clear opening. Reading the frame mask's inner edges was worse (−14% to −21%), so it is not used.
- **Error source:** the A4 is never perfectly flat to the camera (7–15% off its true shape), and that tilt carries straight into the scale.
- **Height:** with the A4 at the top and the phone tilted up, heights come out about 20% short, and the doorway is often cut off by the photo edge.
- **Threshold:** 3.5 cm read as 3.8 cm. Both are above the 3 cm limit (code 202.2), so the direction was right, but the error is close to the gap between "bevel" and "ramp".
- **No false alarms:** bags hanging on the wall, a washing machine, a towel, slippers and a box beside the path were all "off the walking path, noted only".

## Changes made
- A threshold within **±1 cm** of a limit (0.5 cm or 3 cm) is reported as **borderline, needs a manual measurement**: no red, no subsidy item, and the agent may only ask for a check. This is deliberately conservative: at 3.5 cm the verdict was right, but our error is the same size as the margin, so the app does not decide.
- Every height shows **± 1.0 cm** and is labelled a reference value.
- Nothing is measured when it touches the photo's edge (not fully in view).
- Door width is reported as the clear opening, from the doorway's bounding box, with an error of about ±10%.
- Shooting tips: for a threshold, tape the A4 on the lower door frame next to it and shoot from low down; for a door width, tape it in the middle of the door, face the door straight on and get the whole door in view.
- Upload applies the phone's EXIF rotation and strips metadata (including GPS).

Not yet re-tested: photos taken with the new tips.
