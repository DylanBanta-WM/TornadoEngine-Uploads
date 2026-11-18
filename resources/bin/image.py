# Image work for the Uploads module, with OpenCV. Prints JSON.
#   faces <image> <haarcascades dir>         -> [[x, y, w, h], ...]
#   logos <image> <logo> [<logo> ...]        -> [[x, y, w, h, logo index], ...]
#   render <image> <output.png> <boxes.json> -> {"ok": true}; boxes: {"black": [[x,y,w,h]...], "red": [[x,y,w,h]...]}
import json
import math
import sys

import cv2
import numpy as np


LOGO_VOTES = 12  # matches agreeing on one place, pooled over the enlargements


def read(path, flags=cv2.IMREAD_COLOR):
    image = cv2.imread(path, flags)
    if image is None:
        print(json.dumps({"error": "unreadable"}))
        sys.exit(0)
    return image


def faces(path, cascades):
    gray = cv2.equalizeHist(read(path, cv2.IMREAD_GRAYSCALE))
    height, width = gray.shape
    scale = min(1.0, 1600 / max(height, width))
    small = cv2.resize(gray, None, fx=scale, fy=scale) if scale < 1 else gray
    found = []
    for name, flip in (("haarcascade_frontalface_default.xml", False), ("haarcascade_profileface.xml", False), ("haarcascade_profileface.xml", True)):
        detector = cv2.CascadeClassifier(cascades + "/" + name)
        image = cv2.flip(small, 1) if flip else small
        for (x, y, w, h) in detector.detectMultiScale(image, scaleFactor=1.1, minNeighbors=5, minSize=(24, 24)):
            if flip:
                x = image.shape[1] - x - w
            found.append([int(x / scale), int(y / scale), int(w / scale), int(h / scale)])
    # One box per face where detectors overlap.
    if found:
        grouped, _ = cv2.groupRectangles(found + found, 1, 0.3)
        found = [[int(v) for v in box] for box in grouped]
    return found


def logos(path, logo_paths):
    # SIFT keypoints; each match votes for where the logo's center is and how big it is. Votes that agree
    # are a logo, even when it's small or bent (on a shirt, say), where fitting one transform fails.
    # Votes from a few enlargements of the image are pooled, since any one of them can miss.
    scene = read(path, cv2.IMREAD_GRAYSCALE)
    longest = max(scene.shape)
    ups = [up for up in (1.0, 1.25, 1.5, 2.0) if longest * up <= 2000] or [2000 / longest]
    sift = cv2.SIFT_create(nfeatures=12000, contrastThreshold=0.02)
    matcher = cv2.BFMatcher(cv2.NORM_L2)
    logos_ = []
    for logo_path in logo_paths:
        logo = cv2.imread(logo_path, cv2.IMREAD_GRAYSCALE)
        points, descriptors = sift.detectAndCompute(logo, None) if logo is not None else (None, None)
        logos_.append((logo, points, descriptors))
    votes = [[] for _ in logo_paths]
    for up in ups:
        big = cv2.resize(scene, None, fx=up, fy=up, interpolation=cv2.INTER_CUBIC if up > 1 else cv2.INTER_AREA) if up != 1 else scene
        scene_points, scene_descriptors = sift.detectAndCompute(big, None)
        if scene_descriptors is None or len(scene_points) < 2:
            continue
        for index, (logo, logo_points, logo_descriptors) in enumerate(logos_):
            if logo_descriptors is None or len(logo_points) < 5:
                continue
            h, w = logo.shape
            for m, *rest in matcher.knnMatch(logo_descriptors, scene_descriptors, k=2):
                if not rest or m.distance >= 0.8 * rest[0].distance:
                    continue
                seen, known = scene_points[m.trainIdx], logo_points[m.queryIdx]
                scale = seen.size / known.size
                turn = math.radians(seen.angle - known.angle)
                dx, dy = w / 2 - known.pt[0], h / 2 - known.pt[1]
                votes[index].append(((seen.pt[0] + scale * (dx * math.cos(turn) - dy * math.sin(turn))) / up,
                                     (seen.pt[1] + scale * (dx * math.sin(turn) + dy * math.cos(turn))) / up, scale / up))
    found = []
    for index, (logo, _, _) in enumerate(logos_):
        if logo is None:
            continue
        h, w = logo.shape
        pool, taken = votes[index], set()
        while True:
            best = []
            for i, (cx, cy, scale) in enumerate(pool):
                if i in taken:
                    continue
                group = [j for j, (x, y, s) in enumerate(pool) if j not in taken and math.hypot(x - cx, y - cy) < 0.3 * w * scale and 0.6 < s / scale < 1.6]
                if len(group) > len(best):
                    best = group
            if len(best) < LOGO_VOTES:
                break
            taken.update(best)
            cx, cy, scale = (float(np.median([pool[j][k] for j in best])) for k in range(3))
            bw, bh = w * scale * 1.2, h * scale * 1.2
            if bw < 8 or bh < 8 or bw > scene.shape[1] * 1.2 or bh > scene.shape[0] * 1.2:
                continue
            x, y = max(0, int(cx - bw / 2)), max(0, int(cy - bh / 2))
            found.append([x, y, int(min(bw, scene.shape[1] - x)), int(min(bh, scene.shape[0] - y)), index])
    return found


def render(path, output, boxes_path):
    image = read(path)
    with open(boxes_path) as handle:
        boxes = json.load(handle)
    for (x, y, w, h) in boxes.get("black", []):
        cv2.rectangle(image, (x, y), (x + w, y + h), (0, 0, 0), -1)
    thickness = max(3, int(max(image.shape[:2]) / 300))
    for (x, y, w, h) in boxes.get("red", []):
        cv2.rectangle(image, (x, y), (x + w, y + h), (0, 0, 255), thickness)
    cv2.imwrite(output, image)
    return {"ok": True}


command = sys.argv[1]
if command == "faces":
    print(json.dumps(faces(sys.argv[2], sys.argv[3])))
elif command == "logos":
    print(json.dumps(logos(sys.argv[2], sys.argv[3:])))
elif command == "render":
    print(json.dumps(render(sys.argv[2], sys.argv[3], sys.argv[4])))
