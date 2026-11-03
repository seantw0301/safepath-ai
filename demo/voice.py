"""Kokoro narration for demo/cues.json.

  python voice.py tts   CUES OUT_DIR             -> OUT_DIR/NN.wav and OUT_DIR/durations.json
  python voice.py mix   CUES WAV_DIR VIDEO OUT    -> narration placed at each cue's "at", -16 LUFS, AAC
  python voice.py srt   CUES WAV_DIR OUT.srt      -> subtitles, one per cue, timed to the speech
Needs KOKORO_MODEL and KOKORO_VOICES (kokoro-v1.0.onnx, voices-v1.0.bin) and ffmpeg.
"""
import json
import os
import subprocess
import sys

import soundfile as sf


def cues(path):
    return json.load(open(path))


def tts(cfg, out):
    from kokoro_onnx import Kokoro

    k = Kokoro(os.environ['KOKORO_MODEL'], os.environ['KOKORO_VOICES'])
    os.makedirs(out, exist_ok=True)
    durations = []
    for i, c in enumerate(cfg['cues']):
        audio, rate = k.create(c['text'], voice=cfg['voice'], speed=c.get('speed', cfg['speed']))
        sf.write(f'{out}/{i:02d}.wav', audio, rate)
        durations.append(round(len(audio) / rate, 2))
    json.dump(durations, open(f'{out}/durations.json', 'w'))
    print(json.dumps(durations))


def check(cfg, wavs):
    durations = json.load(open(f'{wavs}/durations.json'))
    c = cfg['cues']
    for i in range(len(c) - 1):
        if c[i]['at'] + durations[i] > c[i + 1]['at']:
            sys.exit(f'cue {i} ({durations[i]} s) runs into cue {i + 1}')
    return durations


def mix(cfg, wavs, video, out):
    check(cfg, wavs)
    inputs, filters = ['-i', video], []
    for i, c in enumerate(cfg['cues']):
        inputs += ['-i', f'{wavs}/{i:02d}.wav']
        ms = int(c['at'] * 1000)
        filters.append(f'[{i + 1}]adelay={ms}|{ms},aformat=channel_layouts=stereo[a{i}]')
    n = len(cfg['cues'])
    graph = ';'.join(filters) + ';' + ''.join(f'[a{i}]' for i in range(n)) + \
        f'amix=inputs={n}:normalize=0,apad,loudnorm=I=-16:TP=-1.5:LRA=11[aout]'
    subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', *inputs, '-filter_complex', graph,
                    '-map', '0:v', '-map', '[aout]', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k',
                    '-ar', '48000', '-shortest', '-movflags', '+faststart', out], check=True)


def srt(cfg, wavs, out):
    durations = check(cfg, wavs)
    stamp = lambda s: f'{int(s // 3600):02d}:{int(s % 3600 // 60):02d}:{int(s % 60):02d},{int(s * 1000 % 1000):03d}'
    with open(out, 'w') as f:
        for i, c in enumerate(cfg['cues']):
            f.write(f"{i + 1}\n{stamp(c['at'])} --> {stamp(c['at'] + durations[i])}\n{c['text']}\n\n")


if __name__ == '__main__':
    cmd, cfg = sys.argv[1], cues(sys.argv[2])
    {'tts': tts, 'mix': mix, 'srt': srt}[cmd](cfg, *sys.argv[3:])
