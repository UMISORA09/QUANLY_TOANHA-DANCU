import { createCanvas, installDOM } from '@onirenaud/node-webgl';
installDOM(); // Cung cấp window, document, Image, requestAnimationFrame cho Three.js
import * as THREE from 'three';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Kích thước render HD
const WIDTH = 1280;
const HEIGHT = 720;

// Tạo hình tam giác bo tròn 3 đỉnh mềm mại theo đúng bản vẽ tháp đôi
function createRoundedTriangleShape(radius, cornerRadius = 1.6) {
  const shape = new THREE.Shape();
  const angles = [
    Math.PI / 2,
    Math.PI / 2 + (2 * Math.PI) / 3,
    Math.PI / 2 + (4 * Math.PI) / 3,
  ];
  const pts = angles.map((a) => new THREE.Vector2(Math.cos(a) * radius, Math.sin(a) * radius));
  const p0 = pts[0], p1 = pts[1], p2 = pts[2];

  const getCornerPoints = (prev, cur, next, dist) => {
    const vPrev = new THREE.Vector2().subVectors(prev, cur).normalize().multiplyScalar(dist);
    const vNext = new THREE.Vector2().subVectors(next, cur).normalize().multiplyScalar(dist);
    return {
      start: new THREE.Vector2().addVectors(cur, vPrev),
      end: new THREE.Vector2().addVectors(cur, vNext),
      control: cur,
    };
  };

  const c0 = getCornerPoints(p2, p0, p1, cornerRadius);
  const c1 = getCornerPoints(p0, p1, p2, cornerRadius);
  const c2 = getCornerPoints(p1, p2, p0, cornerRadius);

  shape.moveTo(c0.end.x, c0.end.y);
  shape.lineTo(c1.start.x, c1.start.y);
  shape.quadraticCurveTo(c1.control.x, c1.control.y, c1.end.x, c1.end.y);
  shape.lineTo(c2.start.x, c2.start.y);
  shape.quadraticCurveTo(c2.control.x, c2.control.y, c2.end.x, c2.end.y);
  shape.lineTo(c0.start.x, c0.start.y);
  shape.quadraticCurveTo(c0.control.x, c0.control.y, c0.end.x, c0.end.y);
  shape.closePath();

  return shape;
}

// Tạo hình khối đế Podium chữ L uốn lượn
function createPodiumShape() {
  const shape = new THREE.Shape();
  shape.moveTo(-20, -8);
  shape.bezierCurveTo(-22, -2, -22, 6, -20, 16);
  shape.bezierCurveTo(-18, 22, -10, 24, 0, 22);
  shape.bezierCurveTo(8, 20, 12, 14, 12, 8);
  shape.bezierCurveTo(12, 4, 16, 2, 18, -2);
  shape.bezierCurveTo(20, -6, 18, -10, 14, -12);
  shape.bezierCurveTo(8, -14, 2, -12, -2, -10);
  shape.bezierCurveTo(-8, -8, -14, -8, -20, -8);
  shape.closePath();
  return shape;
}

// Hồ cảnh quan sinh thái
function createLakeShape() {
  const shape = new THREE.Shape();
  shape.moveTo(-16, 14);
  shape.bezierCurveTo(-14, 22, -6, 26, 4, 24);
  shape.bezierCurveTo(12, 22, 18, 16, 16, 10);
  shape.bezierCurveTo(14, 6, 8, 8, 2, 12);
  shape.bezierCurveTo(-4, 16, -10, 14, -16, 14);
  shape.closePath();
  return shape;
}

// Bể bơi vô cực trên mái
function createPoolShape() {
  const shape = new THREE.Shape();
  shape.moveTo(4, 0);
  shape.bezierCurveTo(3, 4, 4, 8, 8, 9);
  shape.bezierCurveTo(12, 10, 14, 6, 14, 2);
  shape.bezierCurveTo(14, -2, 10, -2, 6, -1);
  shape.closePath();
  return shape;
}

async function renderBuildingSnapshot() {
  console.log('[node-webgl] Khởi tạo Headless WebGL2 Canvas...');
  const canvas = createCanvas(WIDTH, HEIGHT);
  const renderer = new THREE.WebGLRenderer({
    canvas,
    antialias: true,
    preserveDrawingBuffer: true,
  });
  renderer.setSize(WIDTH, HEIGHT, false);
  renderer.setClearColor(0x060b14, 1.0); // Nền tối hiện đại sang trọng
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFShadowMap;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.3;

  // Cảnh 3D & Camera isometric
  const scene = new THREE.Scene();
  scene.fog = new THREE.FogExp2(0x060b14, 0.008);

  const aspect = WIDTH / HEIGHT;
  const camera = new THREE.PerspectiveCamera(40, aspect, 0.1, 1000);
  camera.position.set(38, 28, 48);
  camera.lookAt(0, 6, 4);

  // Ánh sáng (Lighting)
  const ambientLight = new THREE.AmbientLight(0x1e293b, 1.0);
  scene.add(ambientLight);

  const sunLight = new THREE.DirectionalLight(0xfff8ea, 2.8);
  sunLight.position.set(45, 75, 40);
  sunLight.castShadow = true;
  sunLight.shadow.mapSize.width = 2048;
  sunLight.shadow.mapSize.height = 2048;
  sunLight.shadow.bias = -0.0003;
  scene.add(sunLight);

  const rimLight = new THREE.DirectionalLight(0x0284c7, 1.6);
  rimLight.position.set(-30, 40, -20);
  scene.add(rimLight);

  // 1. Mặt đất & Lưới BIM
  const groundGeo = new THREE.PlaneGeometry(160, 160);
  const groundMat = new THREE.MeshStandardMaterial({
    color: 0x090f1d,
    roughness: 0.85,
  });
  const ground = new THREE.Mesh(groundGeo, groundMat);
  ground.rotation.x = -Math.PI / 2;
  ground.receiveShadow = true;
  scene.add(ground);

  const grid = new THREE.GridHelper(160, 80, 0x0284c7, 0x1e293b);
  grid.position.y = 0.01;
  scene.add(grid);

  // Trục đường giao thông đô thị & Vòng xuyến
  const roadMat = new THREE.MeshStandardMaterial({ color: 0x111827, roughness: 0.7 });

  const roadEast = new THREE.Mesh(new THREE.PlaneGeometry(16, 120), roadMat);
  roadEast.rotation.x = -Math.PI / 2;
  roadEast.position.set(38, 0.02, 0);
  scene.add(roadEast);

  const roadSouth = new THREE.Mesh(new THREE.PlaneGeometry(120, 16), roadMat);
  roadSouth.rotation.x = -Math.PI / 2;
  roadSouth.position.set(0, 0.02, 38);
  scene.add(roadSouth);

  const roundabout = new THREE.Mesh(new THREE.RingGeometry(8, 22, 32), roadMat);
  roundabout.rotation.x = -Math.PI / 2;
  roundabout.position.set(38, 0.03, 38);
  scene.add(roundabout);

  const island = new THREE.Mesh(
    new THREE.CylinderGeometry(7.6, 7.8, 0.5, 32),
    new THREE.MeshStandardMaterial({ color: 0x064e3b, roughness: 0.9 })
  );
  island.position.set(38, 0.25, 38);
  scene.add(island);

  // 2. Hồ cảnh quan sinh thái uốn lượn
  const lakeShape = createLakeShape();
  const lakeGeo = new THREE.ShapeGeometry(lakeShape);
  const lakeMat = new THREE.MeshStandardMaterial({
    color: 0x0284c7,
    roughness: 0.1,
    metalness: 0.8,
    transparent: true,
    opacity: 0.85,
  });
  const lakeMesh = new THREE.Mesh(lakeGeo, lakeMat);
  lakeMesh.rotation.x = -Math.PI / 2;
  lakeMesh.position.set(0, 0.06, 0);
  scene.add(lakeMesh);

  // 3. Quảng trường trung tâm & Đài phun nước
  const plazaMesh = new THREE.Mesh(
    new THREE.CylinderGeometry(14, 14, 0.15, 32),
    new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.6 })
  );
  plazaMesh.position.set(8, 0.08, 18);
  scene.add(plazaMesh);

  const fountain = new THREE.Mesh(
    new THREE.CylinderGeometry(3.5, 3.8, 0.6, 24),
    new THREE.MeshStandardMaterial({ color: 0x0284c7, metalness: 0.7 })
  );
  fountain.position.set(8, 0.38, 18);
  scene.add(fountain);

  // Cầu đi bộ cảnh quan uốn lượn
  const bridgeCurve = new THREE.CatmullRomCurve3([
    new THREE.Vector3(18, 0.4, 8),
    new THREE.Vector3(14, 1.6, 14),
    new THREE.Vector3(6, 1.8, 18),
    new THREE.Vector3(-4, 1.4, 22),
    new THREE.Vector3(-14, 0.4, 26),
  ]);
  const bridgeGeo = new THREE.TubeGeometry(bridgeCurve, 32, 0.7, 8, false);
  const bridgeMat = new THREE.MeshStandardMaterial({ color: 0xd97706, roughness: 0.4 });
  const bridgeMesh = new THREE.Mesh(bridgeGeo, bridgeMat);
  scene.add(bridgeMesh);

  // 4. Khối đế thương mại uốn lượn chữ L (4 tầng)
  const podiumShape = createPodiumShape();
  const podiumHeight = 4.2;
  const podiumGeo = new THREE.ExtrudeGeometry(podiumShape, {
    depth: podiumHeight,
    bevelEnabled: true,
    bevelSegments: 3,
    bevelSize: 0.25,
    bevelThickness: 0.25,
  });
  podiumGeo.rotateX(-Math.PI / 2);

  const podiumMat = new THREE.MeshStandardMaterial({
    color: 0x1e1b4b,
    roughness: 0.3,
    metalness: 0.5,
    opacity: 0.92,
  });
  const podiumMesh = new THREE.Mesh(podiumGeo, podiumMat);
  podiumMesh.position.set(-2, 0, 2);
  podiumMesh.castShadow = true;
  podiumMesh.receiveShadow = true;
  scene.add(podiumMesh);

  // Dải đèn sọc phân tầng kính khối đế
  for (let f = 1; f <= 3; f++) {
    const ringY = f * 1.05;
    const subGeo = new THREE.ExtrudeGeometry(podiumShape, { depth: 0.08, bevelEnabled: false });
    subGeo.rotateX(-Math.PI / 2);
    const ringMesh = new THREE.Mesh(subGeo, new THREE.MeshBasicMaterial({ color: 0x38bdf8, opacity: 0.6 }));
    ringMesh.position.set(-2, ringY, 2);
    scene.add(ringMesh);
  }

  // 5. Bể bơi vô cực trên mái khối đế (Tầng 5)
  const poolShape = createPoolShape();
  const poolGeo = new THREE.ExtrudeGeometry(poolShape, {
    depth: 0.4,
    bevelEnabled: true,
    bevelSegments: 2,
    bevelSize: 0.1,
    bevelThickness: 0.1,
  });
  poolGeo.rotateX(-Math.PI / 2);
  const poolMesh = new THREE.Mesh(
    poolGeo,
    new THREE.MeshStandardMaterial({
      color: 0x06b6d4,
      roughness: 0.05,
      metalness: 0.9,
      transparent: true,
      opacity: 0.9,
    })
  );
  poolMesh.position.set(2, podiumHeight + 0.05, 4);
  scene.add(poolMesh);

  // Sàn gỗ tắm nắng Decking
  const deckMesh = new THREE.Mesh(
    new THREE.BoxGeometry(16, 0.12, 12),
    new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.6 })
  );
  deckMesh.position.set(7, podiumHeight + 0.06, 5);
  scene.add(deckMesh);

  // 6. Tháp Khách Sạn 28 Tầng (Tháp 10) - Tam giác bo góc
  const hotelShape = createRoundedTriangleShape(7.2, 1.8);
  const hotelFloors = 28;
  const hotelHeight = 25.2;
  const hotelGeo = new THREE.ExtrudeGeometry(hotelShape, {
    depth: hotelHeight,
    bevelEnabled: true,
    bevelSegments: 4,
    bevelSize: 0.2,
    bevelThickness: 0.2,
  });
  hotelGeo.rotateX(-Math.PI / 2);

  const hotelMesh = new THREE.Mesh(
    hotelGeo,
    new THREE.MeshStandardMaterial({
      color: 0x0284c7, // Sapphire Curtain Wall
      roughness: 0.25,
      metalness: 0.7,
      opacity: 0.94,
    })
  );
  hotelMesh.position.set(6, 0, -2);
  hotelMesh.castShadow = true;
  hotelMesh.receiveShadow = true;
  scene.add(hotelMesh);

  // Viền sắc cạnh và các tầng tháp khách sạn
  const hotelEdges = new THREE.LineSegments(
    new THREE.EdgesGeometry(hotelGeo),
    new THREE.LineBasicMaterial({ color: 0x38bdf8, opacity: 0.9 })
  );
  hotelEdges.position.copy(hotelMesh.position);
  scene.add(hotelEdges);

  for (let f = 1; f < hotelFloors; f++) {
    const slabY = f * 0.9;
    const slabGeo = new THREE.ExtrudeGeometry(hotelShape, { depth: 0.06, bevelEnabled: false });
    slabGeo.rotateX(-Math.PI / 2);
    const slab = new THREE.Mesh(slabGeo, new THREE.MeshBasicMaterial({ color: 0x38bdf8, opacity: 0.8 }));
    slab.position.set(6, slabY, -2);
    scene.add(slab);
  }

  // Hàng ban công công-son vươn ra dọc mặt tiền (Sheet 10)
  for (let bf = 4; bf < hotelFloors - 1; bf++) {
    const bY = bf * 0.9;
    const balconySlab = new THREE.Mesh(
      new THREE.BoxGeometry(2.4, 0.1, 1.5),
      new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.2, metalness: 0.5 })
    );
    balconySlab.position.set(6 - 6.6, bY, -2 + 1.2);
    scene.add(balconySlab);
  }

  // Vương miện đỉnh tháp vát chéo một bên (Sheet 10 Mặt đứng Tây - Đông)
  const crownShape = createRoundedTriangleShape(5.5, 1.4);
  const crownGeo = new THREE.ExtrudeGeometry(crownShape, { depth: 2.8, bevelEnabled: true });
  crownGeo.rotateX(-Math.PI / 2);
  const crown = new THREE.Mesh(
    crownGeo,
    new THREE.MeshStandardMaterial({ color: 0x0284c7, metalness: 0.9, roughness: 0.2 })
  );
  crown.position.set(6, hotelHeight, -2);
  crown.rotation.z = -0.15;
  scene.add(crown);

  // Mái vòm cong điêu khắc Cassavas & LED Screen (Sheet 10)
  const canopyCurve = new THREE.CatmullRomCurve3([
    new THREE.Vector3(-22, 0.5, 12),
    new THREE.Vector3(-18, 4.4, 10),
    new THREE.Vector3(-12, 4.8, 6),
  ]);
  const canopyMesh = new THREE.Mesh(
    new THREE.TubeGeometry(canopyCurve, 24, 2.0, 12, false),
    new THREE.MeshStandardMaterial({ color: 0xffffff, metalness: 0.6, roughness: 0.2 })
  );
  scene.add(canopyMesh);

  // 7. Tháp Văn Phòng 12 Tầng (Tháp 11) - Tam giác bo góc
  const officeShape = createRoundedTriangleShape(6.0, 1.5);
  const officeFloors = 12;
  const officeHeight = 11.0;
  const officeGeo = new THREE.ExtrudeGeometry(officeShape, {
    depth: officeHeight,
    bevelEnabled: true,
    bevelSegments: 4,
    bevelSize: 0.2,
    bevelThickness: 0.2,
  });
  officeGeo.rotateX(-Math.PI / 2);

  const officeMesh = new THREE.Mesh(
    officeGeo,
    new THREE.MeshStandardMaterial({
      color: 0x38bdf8,
      roughness: 0.3,
      metalness: 0.6,
      opacity: 0.92,
    })
  );
  officeMesh.position.set(-12, 0, -2);
  officeMesh.castShadow = true;
  officeMesh.receiveShadow = true;
  scene.add(officeMesh);

  const officeEdges = new THREE.LineSegments(
    new THREE.EdgesGeometry(officeGeo),
    new THREE.LineBasicMaterial({ color: 0x7dd3fc, opacity: 0.8 })
  );
  officeEdges.position.copy(officeMesh.position);
  scene.add(officeEdges);

  for (let f = 1; f < officeFloors; f++) {
    const slabY = f * 0.9;
    const slabGeo = new THREE.ExtrudeGeometry(officeShape, { depth: 0.06, bevelEnabled: false });
    slabGeo.rotateX(-Math.PI / 2);
    const slab = new THREE.Mesh(slabGeo, new THREE.MeshBasicMaterial({ color: 0x7dd3fc, opacity: 0.6 }));
    slab.position.set(-12, slabY, -2);
    scene.add(slab);
  }

  // 8. Cầu kính nối trên không (Skybridge tầng 8-10)
  const skybridgeCurve = new THREE.CatmullRomCurve3([
    new THREE.Vector3(-8.5, 8.2, -2),
    new THREE.Vector3(-3.0, 8.4, -1),
    new THREE.Vector3(2.5, 8.2, -2),
  ]);
  const skybridgeGeo = new THREE.TubeGeometry(skybridgeCurve, 20, 1.1, 8, false);
  const skybridge = new THREE.Mesh(
    skybridgeGeo,
    new THREE.MeshStandardMaterial({
      color: 0x38bdf8,
      transparent: true,
      opacity: 0.8,
      metalness: 0.8,
    })
  );
  scene.add(skybridge);

  // Render cảnh
  console.log('[node-webgl] Đang render frame 3D trên GPU/ANGLE...');
  renderer.render(scene, camera);

  // Xuất ra buffer PNG
  const buffer = canvas.toBuffer('image/png');

  const outputDir = path.resolve(__dirname, '../public/images');
  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }

  const outputPath = path.join(outputDir, 'zone_3d_snapshot.png');
  fs.writeFileSync(outputPath, buffer);

  console.log(`[node-webgl] Đã xuất thành công ảnh 3D snapshot: ${outputPath} (${(buffer.length / 1024).toFixed(1)} KB)`);
}

renderBuildingSnapshot().catch((err) => {
  console.error('[node-webgl] Lỗi kết xuất 3D:', err);
  process.exit(1);
});
