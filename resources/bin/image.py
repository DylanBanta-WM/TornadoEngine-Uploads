# Image work for the Uploads module, with OpenCV. Prints JSON.
#   faces <image> <haarcascades dir>         -> [[x, y, w, h], ...]
#   logos <image> <logo> [<logo> ...]        -> [[x, y, w, h, logo index], ...]
#   render <image> <output.png> <boxes.json> -> {"ok": true}; boxes: {"black": [[x,y,w,h]...], "red": [[x,y,w,h]...]}
import json
import sys

import cv2
import numpy as np


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
    scene = read(path, cv2.IMREAD_GRAYSCALE)
    orb = cv2.ORB_create(nfeatures=4000)
    scene_points, scene_descriptors = orb.detectAndCompute(scene, None)
    found = []
    if scene_descriptors is None:
        return found
    matcher = cv2.BFMatcher(cv2.NORM_HAMMING)
    for index, logo_path in enumerate(logo_paths):
        logo = cv2.imread(logo_path, cv2.IMREAD_GRAYSCALE)
        if logo is None:
            continue
        logo_points, logo_descriptors = orb.detectAndCompute(logo, None)
        if logo_descriptors is None or len(logo_points) < 8:
            continue
        pairs = matcher.knnMatch(logo_descriptors, scene_descriptors, k=2)
        good = [m for m, *rest in pairs if rest and m.distance < 0.75 * rest[0].distance]
        if len(good) < 10:
            continue
        source = np.float32([logo_points[m.queryIdx].pt for m in good]).reshape(-1, 1, 2)
        target = np.float32([scene_points[m.trainIdx].pt for m in good]).reshape(-1, 1, 2)
        homography, mask = cv2.findHomography(source, target, cv2.RANSAC, 5.0)
        if homography is None or mask.sum() < 10:
            continue
        h, w = logo.shape
        corners = cv2.perspectiveTransform(np.float32([[0, 0], [w, 0], [w, h], [0, h]]).reshape(-1, 1, 2), homography)
        x, y, bw, bh = cv2.boundingRect(corners)
        if bw > 4 and bh > 4 and bw < scene.shape[1] * 1.5 and bh < scene.shape[0] * 1.5:
            found.append([max(0, int(x)), max(0, int(y)), int(bw), int(bh), index])
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
