"""samtool: parse SAM 3.1 stream output, decode masks, compute measurements.

Usage (planned):
  samtool.py parse   < sam_stream.txt  > tracks.json
  samtool.py measure --tracks tracks.json --frames DIR > measurements.json
"""
import sys


def main() -> int:
    # TODO(M1): use official `meta-sam-parser` to decode masks
    print('samtool: not implemented yet', file=sys.stderr)
    return 1


if __name__ == '__main__':
    sys.exit(main())
