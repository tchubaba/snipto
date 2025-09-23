function getDefaultExportFromCjs(x) {
  return x && x.__esModule && Object.prototype.hasOwnProperty.call(x, "default") ? x["default"] : x;
}
var browser = {};
var canPromise;
var hasRequiredCanPromise;
function requireCanPromise() {
  if (hasRequiredCanPromise) return canPromise;
  hasRequiredCanPromise = 1;
  canPromise = function() {
    return typeof Promise === "function" && Promise.prototype && Promise.prototype.then;
  };
  return canPromise;
}
var qrcode = {};
var utils$1 = {};
var hasRequiredUtils$1;
function requireUtils$1() {
  if (hasRequiredUtils$1) return utils$1;
  hasRequiredUtils$1 = 1;
  let toSJISFunction;
  const CODEWORDS_COUNT = [
    0,
    // Not used
    26,
    44,
    70,
    100,
    134,
    172,
    196,
    242,
    292,
    346,
    404,
    466,
    532,
    581,
    655,
    733,
    815,
    901,
    991,
    1085,
    1156,
    1258,
    1364,
    1474,
    1588,
    1706,
    1828,
    1921,
    2051,
    2185,
    2323,
    2465,
    2611,
    2761,
    2876,
    3034,
    3196,
    3362,
    3532,
    3706
  ];
  utils$1.getSymbolSize = function getSymbolSize(version2) {
    if (!version2) throw new Error('"version" cannot be null or undefined');
    if (version2 < 1 || version2 > 40) throw new Error('"version" should be in range from 1 to 40');
    return version2 * 4 + 17;
  };
  utils$1.getSymbolTotalCodewords = function getSymbolTotalCodewords(version2) {
    return CODEWORDS_COUNT[version2];
  };
  utils$1.getBCHDigit = function(data) {
    let digit = 0;
    while (data !== 0) {
      digit++;
      data >>>= 1;
    }
    return digit;
  };
  utils$1.setToSJISFunction = function setToSJISFunction(f) {
    if (typeof f !== "function") {
      throw new Error('"toSJISFunc" is not a valid function.');
    }
    toSJISFunction = f;
  };
  utils$1.isKanjiModeEnabled = function() {
    return typeof toSJISFunction !== "undefined";
  };
  utils$1.toSJIS = function toSJIS(kanji) {
    return toSJISFunction(kanji);
  };
  return utils$1;
}
var errorCorrectionLevel = {};
var hasRequiredErrorCorrectionLevel;
function requireErrorCorrectionLevel() {
  if (hasRequiredErrorCorrectionLevel) return errorCorrectionLevel;
  hasRequiredErrorCorrectionLevel = 1;
  (function(exports) {
    exports.L = { bit: 1 };
    exports.M = { bit: 0 };
    exports.Q = { bit: 3 };
    exports.H = { bit: 2 };
    function fromString(string) {
      if (typeof string !== "string") {
        throw new Error("Param is not a string");
      }
      const lcStr = string.toLowerCase();
      switch (lcStr) {
        case "l":
        case "low":
          return exports.L;
        case "m":
        case "medium":
          return exports.M;
        case "q":
        case "quartile":
          return exports.Q;
        case "h":
        case "high":
          return exports.H;
        default:
          throw new Error("Unknown EC Level: " + string);
      }
    }
    exports.isValid = function isValid(level) {
      return level && typeof level.bit !== "undefined" && level.bit >= 0 && level.bit < 4;
    };
    exports.from = function from(value, defaultValue) {
      if (exports.isValid(value)) {
        return value;
      }
      try {
        return fromString(value);
      } catch (e) {
        return defaultValue;
      }
    };
  })(errorCorrectionLevel);
  return errorCorrectionLevel;
}
var bitBuffer;
var hasRequiredBitBuffer;
function requireBitBuffer() {
  if (hasRequiredBitBuffer) return bitBuffer;
  hasRequiredBitBuffer = 1;
  function BitBuffer() {
    this.buffer = [];
    this.length = 0;
  }
  BitBuffer.prototype = {
    get: function(index) {
      const bufIndex = Math.floor(index / 8);
      return (this.buffer[bufIndex] >>> 7 - index % 8 & 1) === 1;
    },
    put: function(num, length) {
      for (let i = 0; i < length; i++) {
        this.putBit((num >>> length - i - 1 & 1) === 1);
      }
    },
    getLengthInBits: function() {
      return this.length;
    },
    putBit: function(bit) {
      const bufIndex = Math.floor(this.length / 8);
      if (this.buffer.length <= bufIndex) {
        this.buffer.push(0);
      }
      if (bit) {
        this.buffer[bufIndex] |= 128 >>> this.length % 8;
      }
      this.length++;
    }
  };
  bitBuffer = BitBuffer;
  return bitBuffer;
}
var bitMatrix;
var hasRequiredBitMatrix;
function requireBitMatrix() {
  if (hasRequiredBitMatrix) return bitMatrix;
  hasRequiredBitMatrix = 1;
  function BitMatrix(size) {
    if (!size || size < 1) {
      throw new Error("BitMatrix size must be defined and greater than 0");
    }
    this.size = size;
    this.data = new Uint8Array(size * size);
    this.reservedBit = new Uint8Array(size * size);
  }
  BitMatrix.prototype.set = function(row, col, value, reserved) {
    const index = row * this.size + col;
    this.data[index] = value;
    if (reserved) this.reservedBit[index] = true;
  };
  BitMatrix.prototype.get = function(row, col) {
    return this.data[row * this.size + col];
  };
  BitMatrix.prototype.xor = function(row, col, value) {
    this.data[row * this.size + col] ^= value;
  };
  BitMatrix.prototype.isReserved = function(row, col) {
    return this.reservedBit[row * this.size + col];
  };
  bitMatrix = BitMatrix;
  return bitMatrix;
}
var alignmentPattern = {};
var hasRequiredAlignmentPattern;
function requireAlignmentPattern() {
  if (hasRequiredAlignmentPattern) return alignmentPattern;
  hasRequiredAlignmentPattern = 1;
  (function(exports) {
    const getSymbolSize = requireUtils$1().getSymbolSize;
    exports.getRowColCoords = function getRowColCoords(version2) {
      if (version2 === 1) return [];
      const posCount = Math.floor(version2 / 7) + 2;
      const size = getSymbolSize(version2);
      const intervals = size === 145 ? 26 : Math.ceil((size - 13) / (2 * posCount - 2)) * 2;
      const positions = [size - 7];
      for (let i = 1; i < posCount - 1; i++) {
        positions[i] = positions[i - 1] - intervals;
      }
      positions.push(6);
      return positions.reverse();
    };
    exports.getPositions = function getPositions(version2) {
      const coords = [];
      const pos = exports.getRowColCoords(version2);
      const posLength = pos.length;
      for (let i = 0; i < posLength; i++) {
        for (let j = 0; j < posLength; j++) {
          if (i === 0 && j === 0 || // top-left
          i === 0 && j === posLength - 1 || // bottom-left
          i === posLength - 1 && j === 0) {
            continue;
          }
          coords.push([pos[i], pos[j]]);
        }
      }
      return coords;
    };
  })(alignmentPattern);
  return alignmentPattern;
}
var finderPattern = {};
var hasRequiredFinderPattern;
function requireFinderPattern() {
  if (hasRequiredFinderPattern) return finderPattern;
  hasRequiredFinderPattern = 1;
  const getSymbolSize = requireUtils$1().getSymbolSize;
  const FINDER_PATTERN_SIZE = 7;
  finderPattern.getPositions = function getPositions(version2) {
    const size = getSymbolSize(version2);
    return [
      // top-left
      [0, 0],
      // top-right
      [size - FINDER_PATTERN_SIZE, 0],
      // bottom-left
      [0, size - FINDER_PATTERN_SIZE]
    ];
  };
  return finderPattern;
}
var maskPattern = {};
var hasRequiredMaskPattern;
function requireMaskPattern() {
  if (hasRequiredMaskPattern) return maskPattern;
  hasRequiredMaskPattern = 1;
  (function(exports) {
    exports.Patterns = {
      PATTERN000: 0,
      PATTERN001: 1,
      PATTERN010: 2,
      PATTERN011: 3,
      PATTERN100: 4,
      PATTERN101: 5,
      PATTERN110: 6,
      PATTERN111: 7
    };
    const PenaltyScores = {
      N1: 3,
      N2: 3,
      N3: 40,
      N4: 10
    };
    exports.isValid = function isValid(mask) {
      return mask != null && mask !== "" && !isNaN(mask) && mask >= 0 && mask <= 7;
    };
    exports.from = function from(value) {
      return exports.isValid(value) ? parseInt(value, 10) : void 0;
    };
    exports.getPenaltyN1 = function getPenaltyN1(data) {
      const size = data.size;
      let points = 0;
      let sameCountCol = 0;
      let sameCountRow = 0;
      let lastCol = null;
      let lastRow = null;
      for (let row = 0; row < size; row++) {
        sameCountCol = sameCountRow = 0;
        lastCol = lastRow = null;
        for (let col = 0; col < size; col++) {
          let module = data.get(row, col);
          if (module === lastCol) {
            sameCountCol++;
          } else {
            if (sameCountCol >= 5) points += PenaltyScores.N1 + (sameCountCol - 5);
            lastCol = module;
            sameCountCol = 1;
          }
          module = data.get(col, row);
          if (module === lastRow) {
            sameCountRow++;
          } else {
            if (sameCountRow >= 5) points += PenaltyScores.N1 + (sameCountRow - 5);
            lastRow = module;
            sameCountRow = 1;
          }
        }
        if (sameCountCol >= 5) points += PenaltyScores.N1 + (sameCountCol - 5);
        if (sameCountRow >= 5) points += PenaltyScores.N1 + (sameCountRow - 5);
      }
      return points;
    };
    exports.getPenaltyN2 = function getPenaltyN2(data) {
      const size = data.size;
      let points = 0;
      for (let row = 0; row < size - 1; row++) {
        for (let col = 0; col < size - 1; col++) {
          const last = data.get(row, col) + data.get(row, col + 1) + data.get(row + 1, col) + data.get(row + 1, col + 1);
          if (last === 4 || last === 0) points++;
        }
      }
      return points * PenaltyScores.N2;
    };
    exports.getPenaltyN3 = function getPenaltyN3(data) {
      const size = data.size;
      let points = 0;
      let bitsCol = 0;
      let bitsRow = 0;
      for (let row = 0; row < size; row++) {
        bitsCol = bitsRow = 0;
        for (let col = 0; col < size; col++) {
          bitsCol = bitsCol << 1 & 2047 | data.get(row, col);
          if (col >= 10 && (bitsCol === 1488 || bitsCol === 93)) points++;
          bitsRow = bitsRow << 1 & 2047 | data.get(col, row);
          if (col >= 10 && (bitsRow === 1488 || bitsRow === 93)) points++;
        }
      }
      return points * PenaltyScores.N3;
    };
    exports.getPenaltyN4 = function getPenaltyN4(data) {
      let darkCount = 0;
      const modulesCount = data.data.length;
      for (let i = 0; i < modulesCount; i++) darkCount += data.data[i];
      const k = Math.abs(Math.ceil(darkCount * 100 / modulesCount / 5) - 10);
      return k * PenaltyScores.N4;
    };
    function getMaskAt(maskPattern2, i, j) {
      switch (maskPattern2) {
        case exports.Patterns.PATTERN000:
          return (i + j) % 2 === 0;
        case exports.Patterns.PATTERN001:
          return i % 2 === 0;
        case exports.Patterns.PATTERN010:
          return j % 3 === 0;
        case exports.Patterns.PATTERN011:
          return (i + j) % 3 === 0;
        case exports.Patterns.PATTERN100:
          return (Math.floor(i / 2) + Math.floor(j / 3)) % 2 === 0;
        case exports.Patterns.PATTERN101:
          return i * j % 2 + i * j % 3 === 0;
        case exports.Patterns.PATTERN110:
          return (i * j % 2 + i * j % 3) % 2 === 0;
        case exports.Patterns.PATTERN111:
          return (i * j % 3 + (i + j) % 2) % 2 === 0;
        default:
          throw new Error("bad maskPattern:" + maskPattern2);
      }
    }
    exports.applyMask = function applyMask(pattern, data) {
      const size = data.size;
      for (let col = 0; col < size; col++) {
        for (let row = 0; row < size; row++) {
          if (data.isReserved(row, col)) continue;
          data.xor(row, col, getMaskAt(pattern, row, col));
        }
      }
    };
    exports.getBestMask = function getBestMask(data, setupFormatFunc) {
      const numPatterns = Object.keys(exports.Patterns).length;
      let bestPattern = 0;
      let lowerPenalty = Infinity;
      for (let p = 0; p < numPatterns; p++) {
        setupFormatFunc(p);
        exports.applyMask(p, data);
        const penalty = exports.getPenaltyN1(data) + exports.getPenaltyN2(data) + exports.getPenaltyN3(data) + exports.getPenaltyN4(data);
        exports.applyMask(p, data);
        if (penalty < lowerPenalty) {
          lowerPenalty = penalty;
          bestPattern = p;
        }
      }
      return bestPattern;
    };
  })(maskPattern);
  return maskPattern;
}
var errorCorrectionCode = {};
var hasRequiredErrorCorrectionCode;
function requireErrorCorrectionCode() {
  if (hasRequiredErrorCorrectionCode) return errorCorrectionCode;
  hasRequiredErrorCorrectionCode = 1;
  const ECLevel = requireErrorCorrectionLevel();
  const EC_BLOCKS_TABLE = [
    // L  M  Q  H
    1,
    1,
    1,
    1,
    1,
    1,
    1,
    1,
    1,
    1,
    2,
    2,
    1,
    2,
    2,
    4,
    1,
    2,
    4,
    4,
    2,
    4,
    4,
    4,
    2,
    4,
    6,
    5,
    2,
    4,
    6,
    6,
    2,
    5,
    8,
    8,
    4,
    5,
    8,
    8,
    4,
    5,
    8,
    11,
    4,
    8,
    10,
    11,
    4,
    9,
    12,
    16,
    4,
    9,
    16,
    16,
    6,
    10,
    12,
    18,
    6,
    10,
    17,
    16,
    6,
    11,
    16,
    19,
    6,
    13,
    18,
    21,
    7,
    14,
    21,
    25,
    8,
    16,
    20,
    25,
    8,
    17,
    23,
    25,
    9,
    17,
    23,
    34,
    9,
    18,
    25,
    30,
    10,
    20,
    27,
    32,
    12,
    21,
    29,
    35,
    12,
    23,
    34,
    37,
    12,
    25,
    34,
    40,
    13,
    26,
    35,
    42,
    14,
    28,
    38,
    45,
    15,
    29,
    40,
    48,
    16,
    31,
    43,
    51,
    17,
    33,
    45,
    54,
    18,
    35,
    48,
    57,
    19,
    37,
    51,
    60,
    19,
    38,
    53,
    63,
    20,
    40,
    56,
    66,
    21,
    43,
    59,
    70,
    22,
    45,
    62,
    74,
    24,
    47,
    65,
    77,
    25,
    49,
    68,
    81
  ];
  const EC_CODEWORDS_TABLE = [
    // L  M  Q  H
    7,
    10,
    13,
    17,
    10,
    16,
    22,
    28,
    15,
    26,
    36,
    44,
    20,
    36,
    52,
    64,
    26,
    48,
    72,
    88,
    36,
    64,
    96,
    112,
    40,
    72,
    108,
    130,
    48,
    88,
    132,
    156,
    60,
    110,
    160,
    192,
    72,
    130,
    192,
    224,
    80,
    150,
    224,
    264,
    96,
    176,
    260,
    308,
    104,
    198,
    288,
    352,
    120,
    216,
    320,
    384,
    132,
    240,
    360,
    432,
    144,
    280,
    408,
    480,
    168,
    308,
    448,
    532,
    180,
    338,
    504,
    588,
    196,
    364,
    546,
    650,
    224,
    416,
    600,
    700,
    224,
    442,
    644,
    750,
    252,
    476,
    690,
    816,
    270,
    504,
    750,
    900,
    300,
    560,
    810,
    960,
    312,
    588,
    870,
    1050,
    336,
    644,
    952,
    1110,
    360,
    700,
    1020,
    1200,
    390,
    728,
    1050,
    1260,
    420,
    784,
    1140,
    1350,
    450,
    812,
    1200,
    1440,
    480,
    868,
    1290,
    1530,
    510,
    924,
    1350,
    1620,
    540,
    980,
    1440,
    1710,
    570,
    1036,
    1530,
    1800,
    570,
    1064,
    1590,
    1890,
    600,
    1120,
    1680,
    1980,
    630,
    1204,
    1770,
    2100,
    660,
    1260,
    1860,
    2220,
    720,
    1316,
    1950,
    2310,
    750,
    1372,
    2040,
    2430
  ];
  errorCorrectionCode.getBlocksCount = function getBlocksCount(version2, errorCorrectionLevel2) {
    switch (errorCorrectionLevel2) {
      case ECLevel.L:
        return EC_BLOCKS_TABLE[(version2 - 1) * 4 + 0];
      case ECLevel.M:
        return EC_BLOCKS_TABLE[(version2 - 1) * 4 + 1];
      case ECLevel.Q:
        return EC_BLOCKS_TABLE[(version2 - 1) * 4 + 2];
      case ECLevel.H:
        return EC_BLOCKS_TABLE[(version2 - 1) * 4 + 3];
      default:
        return void 0;
    }
  };
  errorCorrectionCode.getTotalCodewordsCount = function getTotalCodewordsCount(version2, errorCorrectionLevel2) {
    switch (errorCorrectionLevel2) {
      case ECLevel.L:
        return EC_CODEWORDS_TABLE[(version2 - 1) * 4 + 0];
      case ECLevel.M:
        return EC_CODEWORDS_TABLE[(version2 - 1) * 4 + 1];
      case ECLevel.Q:
        return EC_CODEWORDS_TABLE[(version2 - 1) * 4 + 2];
      case ECLevel.H:
        return EC_CODEWORDS_TABLE[(version2 - 1) * 4 + 3];
      default:
        return void 0;
    }
  };
  return errorCorrectionCode;
}
var polynomial = {};
var galoisField = {};
var hasRequiredGaloisField;
function requireGaloisField() {
  if (hasRequiredGaloisField) return galoisField;
  hasRequiredGaloisField = 1;
  const EXP_TABLE = new Uint8Array(512);
  const LOG_TABLE = new Uint8Array(256);
  (function initTables() {
    let x = 1;
    for (let i = 0; i < 255; i++) {
      EXP_TABLE[i] = x;
      LOG_TABLE[x] = i;
      x <<= 1;
      if (x & 256) {
        x ^= 285;
      }
    }
    for (let i = 255; i < 512; i++) {
      EXP_TABLE[i] = EXP_TABLE[i - 255];
    }
  })();
  galoisField.log = function log(n) {
    if (n < 1) throw new Error("log(" + n + ")");
    return LOG_TABLE[n];
  };
  galoisField.exp = function exp(n) {
    return EXP_TABLE[n];
  };
  galoisField.mul = function mul(x, y) {
    if (x === 0 || y === 0) return 0;
    return EXP_TABLE[LOG_TABLE[x] + LOG_TABLE[y]];
  };
  return galoisField;
}
var hasRequiredPolynomial;
function requirePolynomial() {
  if (hasRequiredPolynomial) return polynomial;
  hasRequiredPolynomial = 1;
  (function(exports) {
    const GF = requireGaloisField();
    exports.mul = function mul(p1, p2) {
      const coeff = new Uint8Array(p1.length + p2.length - 1);
      for (let i = 0; i < p1.length; i++) {
        for (let j = 0; j < p2.length; j++) {
          coeff[i + j] ^= GF.mul(p1[i], p2[j]);
        }
      }
      return coeff;
    };
    exports.mod = function mod(divident, divisor) {
      let result = new Uint8Array(divident);
      while (result.length - divisor.length >= 0) {
        const coeff = result[0];
        for (let i = 0; i < divisor.length; i++) {
          result[i] ^= GF.mul(divisor[i], coeff);
        }
        let offset = 0;
        while (offset < result.length && result[offset] === 0) offset++;
        result = result.slice(offset);
      }
      return result;
    };
    exports.generateECPolynomial = function generateECPolynomial(degree) {
      let poly = new Uint8Array([1]);
      for (let i = 0; i < degree; i++) {
        poly = exports.mul(poly, new Uint8Array([1, GF.exp(i)]));
      }
      return poly;
    };
  })(polynomial);
  return polynomial;
}
var reedSolomonEncoder;
var hasRequiredReedSolomonEncoder;
function requireReedSolomonEncoder() {
  if (hasRequiredReedSolomonEncoder) return reedSolomonEncoder;
  hasRequiredReedSolomonEncoder = 1;
  const Polynomial = requirePolynomial();
  function ReedSolomonEncoder(degree) {
    this.genPoly = void 0;
    this.degree = degree;
    if (this.degree) this.initialize(this.degree);
  }
  ReedSolomonEncoder.prototype.initialize = function initialize(degree) {
    this.degree = degree;
    this.genPoly = Polynomial.generateECPolynomial(this.degree);
  };
  ReedSolomonEncoder.prototype.encode = function encode(data) {
    if (!this.genPoly) {
      throw new Error("Encoder not initialized");
    }
    const paddedData = new Uint8Array(data.length + this.degree);
    paddedData.set(data);
    const remainder = Polynomial.mod(paddedData, this.genPoly);
    const start = this.degree - remainder.length;
    if (start > 0) {
      const buff = new Uint8Array(this.degree);
      buff.set(remainder, start);
      return buff;
    }
    return remainder;
  };
  reedSolomonEncoder = ReedSolomonEncoder;
  return reedSolomonEncoder;
}
var version = {};
var mode = {};
var versionCheck = {};
var hasRequiredVersionCheck;
function requireVersionCheck() {
  if (hasRequiredVersionCheck) return versionCheck;
  hasRequiredVersionCheck = 1;
  versionCheck.isValid = function isValid(version2) {
    return !isNaN(version2) && version2 >= 1 && version2 <= 40;
  };
  return versionCheck;
}
var regex = {};
var hasRequiredRegex;
function requireRegex() {
  if (hasRequiredRegex) return regex;
  hasRequiredRegex = 1;
  const numeric = "[0-9]+";
  const alphanumeric = "[A-Z $%*+\\-./:]+";
  let kanji = "(?:[u3000-u303F]|[u3040-u309F]|[u30A0-u30FF]|[uFF00-uFFEF]|[u4E00-u9FAF]|[u2605-u2606]|[u2190-u2195]|u203B|[u2010u2015u2018u2019u2025u2026u201Cu201Du2225u2260]|[u0391-u0451]|[u00A7u00A8u00B1u00B4u00D7u00F7])+";
  kanji = kanji.replace(/u/g, "\\u");
  const byte = "(?:(?![A-Z0-9 $%*+\\-./:]|" + kanji + ")(?:.|[\r\n]))+";
  regex.KANJI = new RegExp(kanji, "g");
  regex.BYTE_KANJI = new RegExp("[^A-Z0-9 $%*+\\-./:]+", "g");
  regex.BYTE = new RegExp(byte, "g");
  regex.NUMERIC = new RegExp(numeric, "g");
  regex.ALPHANUMERIC = new RegExp(alphanumeric, "g");
  const TEST_KANJI = new RegExp("^" + kanji + "$");
  const TEST_NUMERIC = new RegExp("^" + numeric + "$");
  const TEST_ALPHANUMERIC = new RegExp("^[A-Z0-9 $%*+\\-./:]+$");
  regex.testKanji = function testKanji(str) {
    return TEST_KANJI.test(str);
  };
  regex.testNumeric = function testNumeric(str) {
    return TEST_NUMERIC.test(str);
  };
  regex.testAlphanumeric = function testAlphanumeric(str) {
    return TEST_ALPHANUMERIC.test(str);
  };
  return regex;
}
var hasRequiredMode;
function requireMode() {
  if (hasRequiredMode) return mode;
  hasRequiredMode = 1;
  (function(exports) {
    const VersionCheck = requireVersionCheck();
    const Regex = requireRegex();
    exports.NUMERIC = {
      id: "Numeric",
      bit: 1 << 0,
      ccBits: [10, 12, 14]
    };
    exports.ALPHANUMERIC = {
      id: "Alphanumeric",
      bit: 1 << 1,
      ccBits: [9, 11, 13]
    };
    exports.BYTE = {
      id: "Byte",
      bit: 1 << 2,
      ccBits: [8, 16, 16]
    };
    exports.KANJI = {
      id: "Kanji",
      bit: 1 << 3,
      ccBits: [8, 10, 12]
    };
    exports.MIXED = {
      bit: -1
    };
    exports.getCharCountIndicator = function getCharCountIndicator(mode2, version2) {
      if (!mode2.ccBits) throw new Error("Invalid mode: " + mode2);
      if (!VersionCheck.isValid(version2)) {
        throw new Error("Invalid version: " + version2);
      }
      if (version2 >= 1 && version2 < 10) return mode2.ccBits[0];
      else if (version2 < 27) return mode2.ccBits[1];
      return mode2.ccBits[2];
    };
    exports.getBestModeForData = function getBestModeForData(dataStr) {
      if (Regex.testNumeric(dataStr)) return exports.NUMERIC;
      else if (Regex.testAlphanumeric(dataStr)) return exports.ALPHANUMERIC;
      else if (Regex.testKanji(dataStr)) return exports.KANJI;
      else return exports.BYTE;
    };
    exports.toString = function toString(mode2) {
      if (mode2 && mode2.id) return mode2.id;
      throw new Error("Invalid mode");
    };
    exports.isValid = function isValid(mode2) {
      return mode2 && mode2.bit && mode2.ccBits;
    };
    function fromString(string) {
      if (typeof string !== "string") {
        throw new Error("Param is not a string");
      }
      const lcStr = string.toLowerCase();
      switch (lcStr) {
        case "numeric":
          return exports.NUMERIC;
        case "alphanumeric":
          return exports.ALPHANUMERIC;
        case "kanji":
          return exports.KANJI;
        case "byte":
          return exports.BYTE;
        default:
          throw new Error("Unknown mode: " + string);
      }
    }
    exports.from = function from(value, defaultValue) {
      if (exports.isValid(value)) {
        return value;
      }
      try {
        return fromString(value);
      } catch (e) {
        return defaultValue;
      }
    };
  })(mode);
  return mode;
}
var hasRequiredVersion;
function requireVersion() {
  if (hasRequiredVersion) return version;
  hasRequiredVersion = 1;
  (function(exports) {
    const Utils = requireUtils$1();
    const ECCode = requireErrorCorrectionCode();
    const ECLevel = requireErrorCorrectionLevel();
    const Mode = requireMode();
    const VersionCheck = requireVersionCheck();
    const G18 = 1 << 12 | 1 << 11 | 1 << 10 | 1 << 9 | 1 << 8 | 1 << 5 | 1 << 2 | 1 << 0;
    const G18_BCH = Utils.getBCHDigit(G18);
    function getBestVersionForDataLength(mode2, length, errorCorrectionLevel2) {
      for (let currentVersion = 1; currentVersion <= 40; currentVersion++) {
        if (length <= exports.getCapacity(currentVersion, errorCorrectionLevel2, mode2)) {
          return currentVersion;
        }
      }
      return void 0;
    }
    function getReservedBitsCount(mode2, version2) {
      return Mode.getCharCountIndicator(mode2, version2) + 4;
    }
    function getTotalBitsFromDataArray(segments2, version2) {
      let totalBits = 0;
      segments2.forEach(function(data) {
        const reservedBits = getReservedBitsCount(data.mode, version2);
        totalBits += reservedBits + data.getBitsLength();
      });
      return totalBits;
    }
    function getBestVersionForMixedData(segments2, errorCorrectionLevel2) {
      for (let currentVersion = 1; currentVersion <= 40; currentVersion++) {
        const length = getTotalBitsFromDataArray(segments2, currentVersion);
        if (length <= exports.getCapacity(currentVersion, errorCorrectionLevel2, Mode.MIXED)) {
          return currentVersion;
        }
      }
      return void 0;
    }
    exports.from = function from(value, defaultValue) {
      if (VersionCheck.isValid(value)) {
        return parseInt(value, 10);
      }
      return defaultValue;
    };
    exports.getCapacity = function getCapacity(version2, errorCorrectionLevel2, mode2) {
      if (!VersionCheck.isValid(version2)) {
        throw new Error("Invalid QR Code version");
      }
      if (typeof mode2 === "undefined") mode2 = Mode.BYTE;
      const totalCodewords = Utils.getSymbolTotalCodewords(version2);
      const ecTotalCodewords = ECCode.getTotalCodewordsCount(version2, errorCorrectionLevel2);
      const dataTotalCodewordsBits = (totalCodewords - ecTotalCodewords) * 8;
      if (mode2 === Mode.MIXED) return dataTotalCodewordsBits;
      const usableBits = dataTotalCodewordsBits - getReservedBitsCount(mode2, version2);
      switch (mode2) {
        case Mode.NUMERIC:
          return Math.floor(usableBits / 10 * 3);
        case Mode.ALPHANUMERIC:
          return Math.floor(usableBits / 11 * 2);
        case Mode.KANJI:
          return Math.floor(usableBits / 13);
        case Mode.BYTE:
        default:
          return Math.floor(usableBits / 8);
      }
    };
    exports.getBestVersionForData = function getBestVersionForData(data, errorCorrectionLevel2) {
      let seg;
      const ecl = ECLevel.from(errorCorrectionLevel2, ECLevel.M);
      if (Array.isArray(data)) {
        if (data.length > 1) {
          return getBestVersionForMixedData(data, ecl);
        }
        if (data.length === 0) {
          return 1;
        }
        seg = data[0];
      } else {
        seg = data;
      }
      return getBestVersionForDataLength(seg.mode, seg.getLength(), ecl);
    };
    exports.getEncodedBits = function getEncodedBits(version2) {
      if (!VersionCheck.isValid(version2) || version2 < 7) {
        throw new Error("Invalid QR Code version");
      }
      let d = version2 << 12;
      while (Utils.getBCHDigit(d) - G18_BCH >= 0) {
        d ^= G18 << Utils.getBCHDigit(d) - G18_BCH;
      }
      return version2 << 12 | d;
    };
  })(version);
  return version;
}
var formatInfo = {};
var hasRequiredFormatInfo;
function requireFormatInfo() {
  if (hasRequiredFormatInfo) return formatInfo;
  hasRequiredFormatInfo = 1;
  const Utils = requireUtils$1();
  const G15 = 1 << 10 | 1 << 8 | 1 << 5 | 1 << 4 | 1 << 2 | 1 << 1 | 1 << 0;
  const G15_MASK = 1 << 14 | 1 << 12 | 1 << 10 | 1 << 4 | 1 << 1;
  const G15_BCH = Utils.getBCHDigit(G15);
  formatInfo.getEncodedBits = function getEncodedBits(errorCorrectionLevel2, mask) {
    const data = errorCorrectionLevel2.bit << 3 | mask;
    let d = data << 10;
    while (Utils.getBCHDigit(d) - G15_BCH >= 0) {
      d ^= G15 << Utils.getBCHDigit(d) - G15_BCH;
    }
    return (data << 10 | d) ^ G15_MASK;
  };
  return formatInfo;
}
var segments = {};
var numericData;
var hasRequiredNumericData;
function requireNumericData() {
  if (hasRequiredNumericData) return numericData;
  hasRequiredNumericData = 1;
  const Mode = requireMode();
  function NumericData(data) {
    this.mode = Mode.NUMERIC;
    this.data = data.toString();
  }
  NumericData.getBitsLength = function getBitsLength(length) {
    return 10 * Math.floor(length / 3) + (length % 3 ? length % 3 * 3 + 1 : 0);
  };
  NumericData.prototype.getLength = function getLength() {
    return this.data.length;
  };
  NumericData.prototype.getBitsLength = function getBitsLength() {
    return NumericData.getBitsLength(this.data.length);
  };
  NumericData.prototype.write = function write(bitBuffer2) {
    let i, group, value;
    for (i = 0; i + 3 <= this.data.length; i += 3) {
      group = this.data.substr(i, 3);
      value = parseInt(group, 10);
      bitBuffer2.put(value, 10);
    }
    const remainingNum = this.data.length - i;
    if (remainingNum > 0) {
      group = this.data.substr(i);
      value = parseInt(group, 10);
      bitBuffer2.put(value, remainingNum * 3 + 1);
    }
  };
  numericData = NumericData;
  return numericData;
}
var alphanumericData;
var hasRequiredAlphanumericData;
function requireAlphanumericData() {
  if (hasRequiredAlphanumericData) return alphanumericData;
  hasRequiredAlphanumericData = 1;
  const Mode = requireMode();
  const ALPHA_NUM_CHARS = [
    "0",
    "1",
    "2",
    "3",
    "4",
    "5",
    "6",
    "7",
    "8",
    "9",
    "A",
    "B",
    "C",
    "D",
    "E",
    "F",
    "G",
    "H",
    "I",
    "J",
    "K",
    "L",
    "M",
    "N",
    "O",
    "P",
    "Q",
    "R",
    "S",
    "T",
    "U",
    "V",
    "W",
    "X",
    "Y",
    "Z",
    " ",
    "$",
    "%",
    "*",
    "+",
    "-",
    ".",
    "/",
    ":"
  ];
  function AlphanumericData(data) {
    this.mode = Mode.ALPHANUMERIC;
    this.data = data;
  }
  AlphanumericData.getBitsLength = function getBitsLength(length) {
    return 11 * Math.floor(length / 2) + 6 * (length % 2);
  };
  AlphanumericData.prototype.getLength = function getLength() {
    return this.data.length;
  };
  AlphanumericData.prototype.getBitsLength = function getBitsLength() {
    return AlphanumericData.getBitsLength(this.data.length);
  };
  AlphanumericData.prototype.write = function write(bitBuffer2) {
    let i;
    for (i = 0; i + 2 <= this.data.length; i += 2) {
      let value = ALPHA_NUM_CHARS.indexOf(this.data[i]) * 45;
      value += ALPHA_NUM_CHARS.indexOf(this.data[i + 1]);
      bitBuffer2.put(value, 11);
    }
    if (this.data.length % 2) {
      bitBuffer2.put(ALPHA_NUM_CHARS.indexOf(this.data[i]), 6);
    }
  };
  alphanumericData = AlphanumericData;
  return alphanumericData;
}
var byteData;
var hasRequiredByteData;
function requireByteData() {
  if (hasRequiredByteData) return byteData;
  hasRequiredByteData = 1;
  const Mode = requireMode();
  function ByteData(data) {
    this.mode = Mode.BYTE;
    if (typeof data === "string") {
      this.data = new TextEncoder().encode(data);
    } else {
      this.data = new Uint8Array(data);
    }
  }
  ByteData.getBitsLength = function getBitsLength(length) {
    return length * 8;
  };
  ByteData.prototype.getLength = function getLength() {
    return this.data.length;
  };
  ByteData.prototype.getBitsLength = function getBitsLength() {
    return ByteData.getBitsLength(this.data.length);
  };
  ByteData.prototype.write = function(bitBuffer2) {
    for (let i = 0, l = this.data.length; i < l; i++) {
      bitBuffer2.put(this.data[i], 8);
    }
  };
  byteData = ByteData;
  return byteData;
}
var kanjiData;
var hasRequiredKanjiData;
function requireKanjiData() {
  if (hasRequiredKanjiData) return kanjiData;
  hasRequiredKanjiData = 1;
  const Mode = requireMode();
  const Utils = requireUtils$1();
  function KanjiData(data) {
    this.mode = Mode.KANJI;
    this.data = data;
  }
  KanjiData.getBitsLength = function getBitsLength(length) {
    return length * 13;
  };
  KanjiData.prototype.getLength = function getLength() {
    return this.data.length;
  };
  KanjiData.prototype.getBitsLength = function getBitsLength() {
    return KanjiData.getBitsLength(this.data.length);
  };
  KanjiData.prototype.write = function(bitBuffer2) {
    let i;
    for (i = 0; i < this.data.length; i++) {
      let value = Utils.toSJIS(this.data[i]);
      if (value >= 33088 && value <= 40956) {
        value -= 33088;
      } else if (value >= 57408 && value <= 60351) {
        value -= 49472;
      } else {
        throw new Error(
          "Invalid SJIS character: " + this.data[i] + "\nMake sure your charset is UTF-8"
        );
      }
      value = (value >>> 8 & 255) * 192 + (value & 255);
      bitBuffer2.put(value, 13);
    }
  };
  kanjiData = KanjiData;
  return kanjiData;
}
var dijkstra = { exports: {} };
var hasRequiredDijkstra;
function requireDijkstra() {
  if (hasRequiredDijkstra) return dijkstra.exports;
  hasRequiredDijkstra = 1;
  (function(module) {
    var dijkstra2 = {
      single_source_shortest_paths: function(graph, s, d) {
        var predecessors = {};
        var costs = {};
        costs[s] = 0;
        var open = dijkstra2.PriorityQueue.make();
        open.push(s, 0);
        var closest, u, v, cost_of_s_to_u, adjacent_nodes, cost_of_e, cost_of_s_to_u_plus_cost_of_e, cost_of_s_to_v, first_visit;
        while (!open.empty()) {
          closest = open.pop();
          u = closest.value;
          cost_of_s_to_u = closest.cost;
          adjacent_nodes = graph[u] || {};
          for (v in adjacent_nodes) {
            if (adjacent_nodes.hasOwnProperty(v)) {
              cost_of_e = adjacent_nodes[v];
              cost_of_s_to_u_plus_cost_of_e = cost_of_s_to_u + cost_of_e;
              cost_of_s_to_v = costs[v];
              first_visit = typeof costs[v] === "undefined";
              if (first_visit || cost_of_s_to_v > cost_of_s_to_u_plus_cost_of_e) {
                costs[v] = cost_of_s_to_u_plus_cost_of_e;
                open.push(v, cost_of_s_to_u_plus_cost_of_e);
                predecessors[v] = u;
              }
            }
          }
        }
        if (typeof d !== "undefined" && typeof costs[d] === "undefined") {
          var msg = ["Could not find a path from ", s, " to ", d, "."].join("");
          throw new Error(msg);
        }
        return predecessors;
      },
      extract_shortest_path_from_predecessor_list: function(predecessors, d) {
        var nodes = [];
        var u = d;
        while (u) {
          nodes.push(u);
          predecessors[u];
          u = predecessors[u];
        }
        nodes.reverse();
        return nodes;
      },
      find_path: function(graph, s, d) {
        var predecessors = dijkstra2.single_source_shortest_paths(graph, s, d);
        return dijkstra2.extract_shortest_path_from_predecessor_list(
          predecessors,
          d
        );
      },
      /**
       * A very naive priority queue implementation.
       */
      PriorityQueue: {
        make: function(opts) {
          var T = dijkstra2.PriorityQueue, t = {}, key;
          opts = opts || {};
          for (key in T) {
            if (T.hasOwnProperty(key)) {
              t[key] = T[key];
            }
          }
          t.queue = [];
          t.sorter = opts.sorter || T.default_sorter;
          return t;
        },
        default_sorter: function(a, b) {
          return a.cost - b.cost;
        },
        /**
         * Add a new item to the queue and ensure the highest priority element
         * is at the front of the queue.
         */
        push: function(value, cost) {
          var item = { value, cost };
          this.queue.push(item);
          this.queue.sort(this.sorter);
        },
        /**
         * Return the highest priority element in the queue.
         */
        pop: function() {
          return this.queue.shift();
        },
        empty: function() {
          return this.queue.length === 0;
        }
      }
    };
    {
      module.exports = dijkstra2;
    }
  })(dijkstra);
  return dijkstra.exports;
}
var hasRequiredSegments;
function requireSegments() {
  if (hasRequiredSegments) return segments;
  hasRequiredSegments = 1;
  (function(exports) {
    const Mode = requireMode();
    const NumericData = requireNumericData();
    const AlphanumericData = requireAlphanumericData();
    const ByteData = requireByteData();
    const KanjiData = requireKanjiData();
    const Regex = requireRegex();
    const Utils = requireUtils$1();
    const dijkstra2 = requireDijkstra();
    function getStringByteLength(str) {
      return unescape(encodeURIComponent(str)).length;
    }
    function getSegments(regex2, mode2, str) {
      const segments2 = [];
      let result;
      while ((result = regex2.exec(str)) !== null) {
        segments2.push({
          data: result[0],
          index: result.index,
          mode: mode2,
          length: result[0].length
        });
      }
      return segments2;
    }
    function getSegmentsFromString(dataStr) {
      const numSegs = getSegments(Regex.NUMERIC, Mode.NUMERIC, dataStr);
      const alphaNumSegs = getSegments(Regex.ALPHANUMERIC, Mode.ALPHANUMERIC, dataStr);
      let byteSegs;
      let kanjiSegs;
      if (Utils.isKanjiModeEnabled()) {
        byteSegs = getSegments(Regex.BYTE, Mode.BYTE, dataStr);
        kanjiSegs = getSegments(Regex.KANJI, Mode.KANJI, dataStr);
      } else {
        byteSegs = getSegments(Regex.BYTE_KANJI, Mode.BYTE, dataStr);
        kanjiSegs = [];
      }
      const segs = numSegs.concat(alphaNumSegs, byteSegs, kanjiSegs);
      return segs.sort(function(s1, s2) {
        return s1.index - s2.index;
      }).map(function(obj) {
        return {
          data: obj.data,
          mode: obj.mode,
          length: obj.length
        };
      });
    }
    function getSegmentBitsLength(length, mode2) {
      switch (mode2) {
        case Mode.NUMERIC:
          return NumericData.getBitsLength(length);
        case Mode.ALPHANUMERIC:
          return AlphanumericData.getBitsLength(length);
        case Mode.KANJI:
          return KanjiData.getBitsLength(length);
        case Mode.BYTE:
          return ByteData.getBitsLength(length);
      }
    }
    function mergeSegments(segs) {
      return segs.reduce(function(acc, curr) {
        const prevSeg = acc.length - 1 >= 0 ? acc[acc.length - 1] : null;
        if (prevSeg && prevSeg.mode === curr.mode) {
          acc[acc.length - 1].data += curr.data;
          return acc;
        }
        acc.push(curr);
        return acc;
      }, []);
    }
    function buildNodes(segs) {
      const nodes = [];
      for (let i = 0; i < segs.length; i++) {
        const seg = segs[i];
        switch (seg.mode) {
          case Mode.NUMERIC:
            nodes.push([
              seg,
              { data: seg.data, mode: Mode.ALPHANUMERIC, length: seg.length },
              { data: seg.data, mode: Mode.BYTE, length: seg.length }
            ]);
            break;
          case Mode.ALPHANUMERIC:
            nodes.push([
              seg,
              { data: seg.data, mode: Mode.BYTE, length: seg.length }
            ]);
            break;
          case Mode.KANJI:
            nodes.push([
              seg,
              { data: seg.data, mode: Mode.BYTE, length: getStringByteLength(seg.data) }
            ]);
            break;
          case Mode.BYTE:
            nodes.push([
              { data: seg.data, mode: Mode.BYTE, length: getStringByteLength(seg.data) }
            ]);
        }
      }
      return nodes;
    }
    function buildGraph(nodes, version2) {
      const table = {};
      const graph = { start: {} };
      let prevNodeIds = ["start"];
      for (let i = 0; i < nodes.length; i++) {
        const nodeGroup = nodes[i];
        const currentNodeIds = [];
        for (let j = 0; j < nodeGroup.length; j++) {
          const node = nodeGroup[j];
          const key = "" + i + j;
          currentNodeIds.push(key);
          table[key] = { node, lastCount: 0 };
          graph[key] = {};
          for (let n = 0; n < prevNodeIds.length; n++) {
            const prevNodeId = prevNodeIds[n];
            if (table[prevNodeId] && table[prevNodeId].node.mode === node.mode) {
              graph[prevNodeId][key] = getSegmentBitsLength(table[prevNodeId].lastCount + node.length, node.mode) - getSegmentBitsLength(table[prevNodeId].lastCount, node.mode);
              table[prevNodeId].lastCount += node.length;
            } else {
              if (table[prevNodeId]) table[prevNodeId].lastCount = node.length;
              graph[prevNodeId][key] = getSegmentBitsLength(node.length, node.mode) + 4 + Mode.getCharCountIndicator(node.mode, version2);
            }
          }
        }
        prevNodeIds = currentNodeIds;
      }
      for (let n = 0; n < prevNodeIds.length; n++) {
        graph[prevNodeIds[n]].end = 0;
      }
      return { map: graph, table };
    }
    function buildSingleSegment(data, modesHint) {
      let mode2;
      const bestMode = Mode.getBestModeForData(data);
      mode2 = Mode.from(modesHint, bestMode);
      if (mode2 !== Mode.BYTE && mode2.bit < bestMode.bit) {
        throw new Error('"' + data + '" cannot be encoded with mode ' + Mode.toString(mode2) + ".\n Suggested mode is: " + Mode.toString(bestMode));
      }
      if (mode2 === Mode.KANJI && !Utils.isKanjiModeEnabled()) {
        mode2 = Mode.BYTE;
      }
      switch (mode2) {
        case Mode.NUMERIC:
          return new NumericData(data);
        case Mode.ALPHANUMERIC:
          return new AlphanumericData(data);
        case Mode.KANJI:
          return new KanjiData(data);
        case Mode.BYTE:
          return new ByteData(data);
      }
    }
    exports.fromArray = function fromArray(array) {
      return array.reduce(function(acc, seg) {
        if (typeof seg === "string") {
          acc.push(buildSingleSegment(seg, null));
        } else if (seg.data) {
          acc.push(buildSingleSegment(seg.data, seg.mode));
        }
        return acc;
      }, []);
    };
    exports.fromString = function fromString(data, version2) {
      const segs = getSegmentsFromString(data, Utils.isKanjiModeEnabled());
      const nodes = buildNodes(segs);
      const graph = buildGraph(nodes, version2);
      const path = dijkstra2.find_path(graph.map, "start", "end");
      const optimizedSegs = [];
      for (let i = 1; i < path.length - 1; i++) {
        optimizedSegs.push(graph.table[path[i]].node);
      }
      return exports.fromArray(mergeSegments(optimizedSegs));
    };
    exports.rawSplit = function rawSplit(data) {
      return exports.fromArray(
        getSegmentsFromString(data, Utils.isKanjiModeEnabled())
      );
    };
  })(segments);
  return segments;
}
var hasRequiredQrcode;
function requireQrcode() {
  if (hasRequiredQrcode) return qrcode;
  hasRequiredQrcode = 1;
  const Utils = requireUtils$1();
  const ECLevel = requireErrorCorrectionLevel();
  const BitBuffer = requireBitBuffer();
  const BitMatrix = requireBitMatrix();
  const AlignmentPattern = requireAlignmentPattern();
  const FinderPattern = requireFinderPattern();
  const MaskPattern = requireMaskPattern();
  const ECCode = requireErrorCorrectionCode();
  const ReedSolomonEncoder = requireReedSolomonEncoder();
  const Version = requireVersion();
  const FormatInfo = requireFormatInfo();
  const Mode = requireMode();
  const Segments = requireSegments();
  function setupFinderPattern(matrix, version2) {
    const size = matrix.size;
    const pos = FinderPattern.getPositions(version2);
    for (let i = 0; i < pos.length; i++) {
      const row = pos[i][0];
      const col = pos[i][1];
      for (let r = -1; r <= 7; r++) {
        if (row + r <= -1 || size <= row + r) continue;
        for (let c = -1; c <= 7; c++) {
          if (col + c <= -1 || size <= col + c) continue;
          if (r >= 0 && r <= 6 && (c === 0 || c === 6) || c >= 0 && c <= 6 && (r === 0 || r === 6) || r >= 2 && r <= 4 && c >= 2 && c <= 4) {
            matrix.set(row + r, col + c, true, true);
          } else {
            matrix.set(row + r, col + c, false, true);
          }
        }
      }
    }
  }
  function setupTimingPattern(matrix) {
    const size = matrix.size;
    for (let r = 8; r < size - 8; r++) {
      const value = r % 2 === 0;
      matrix.set(r, 6, value, true);
      matrix.set(6, r, value, true);
    }
  }
  function setupAlignmentPattern(matrix, version2) {
    const pos = AlignmentPattern.getPositions(version2);
    for (let i = 0; i < pos.length; i++) {
      const row = pos[i][0];
      const col = pos[i][1];
      for (let r = -2; r <= 2; r++) {
        for (let c = -2; c <= 2; c++) {
          if (r === -2 || r === 2 || c === -2 || c === 2 || r === 0 && c === 0) {
            matrix.set(row + r, col + c, true, true);
          } else {
            matrix.set(row + r, col + c, false, true);
          }
        }
      }
    }
  }
  function setupVersionInfo(matrix, version2) {
    const size = matrix.size;
    const bits = Version.getEncodedBits(version2);
    let row, col, mod;
    for (let i = 0; i < 18; i++) {
      row = Math.floor(i / 3);
      col = i % 3 + size - 8 - 3;
      mod = (bits >> i & 1) === 1;
      matrix.set(row, col, mod, true);
      matrix.set(col, row, mod, true);
    }
  }
  function setupFormatInfo(matrix, errorCorrectionLevel2, maskPattern2) {
    const size = matrix.size;
    const bits = FormatInfo.getEncodedBits(errorCorrectionLevel2, maskPattern2);
    let i, mod;
    for (i = 0; i < 15; i++) {
      mod = (bits >> i & 1) === 1;
      if (i < 6) {
        matrix.set(i, 8, mod, true);
      } else if (i < 8) {
        matrix.set(i + 1, 8, mod, true);
      } else {
        matrix.set(size - 15 + i, 8, mod, true);
      }
      if (i < 8) {
        matrix.set(8, size - i - 1, mod, true);
      } else if (i < 9) {
        matrix.set(8, 15 - i - 1 + 1, mod, true);
      } else {
        matrix.set(8, 15 - i - 1, mod, true);
      }
    }
    matrix.set(size - 8, 8, 1, true);
  }
  function setupData(matrix, data) {
    const size = matrix.size;
    let inc = -1;
    let row = size - 1;
    let bitIndex = 7;
    let byteIndex = 0;
    for (let col = size - 1; col > 0; col -= 2) {
      if (col === 6) col--;
      while (true) {
        for (let c = 0; c < 2; c++) {
          if (!matrix.isReserved(row, col - c)) {
            let dark = false;
            if (byteIndex < data.length) {
              dark = (data[byteIndex] >>> bitIndex & 1) === 1;
            }
            matrix.set(row, col - c, dark);
            bitIndex--;
            if (bitIndex === -1) {
              byteIndex++;
              bitIndex = 7;
            }
          }
        }
        row += inc;
        if (row < 0 || size <= row) {
          row -= inc;
          inc = -inc;
          break;
        }
      }
    }
  }
  function createData(version2, errorCorrectionLevel2, segments2) {
    const buffer = new BitBuffer();
    segments2.forEach(function(data) {
      buffer.put(data.mode.bit, 4);
      buffer.put(data.getLength(), Mode.getCharCountIndicator(data.mode, version2));
      data.write(buffer);
    });
    const totalCodewords = Utils.getSymbolTotalCodewords(version2);
    const ecTotalCodewords = ECCode.getTotalCodewordsCount(version2, errorCorrectionLevel2);
    const dataTotalCodewordsBits = (totalCodewords - ecTotalCodewords) * 8;
    if (buffer.getLengthInBits() + 4 <= dataTotalCodewordsBits) {
      buffer.put(0, 4);
    }
    while (buffer.getLengthInBits() % 8 !== 0) {
      buffer.putBit(0);
    }
    const remainingByte = (dataTotalCodewordsBits - buffer.getLengthInBits()) / 8;
    for (let i = 0; i < remainingByte; i++) {
      buffer.put(i % 2 ? 17 : 236, 8);
    }
    return createCodewords(buffer, version2, errorCorrectionLevel2);
  }
  function createCodewords(bitBuffer2, version2, errorCorrectionLevel2) {
    const totalCodewords = Utils.getSymbolTotalCodewords(version2);
    const ecTotalCodewords = ECCode.getTotalCodewordsCount(version2, errorCorrectionLevel2);
    const dataTotalCodewords = totalCodewords - ecTotalCodewords;
    const ecTotalBlocks = ECCode.getBlocksCount(version2, errorCorrectionLevel2);
    const blocksInGroup2 = totalCodewords % ecTotalBlocks;
    const blocksInGroup1 = ecTotalBlocks - blocksInGroup2;
    const totalCodewordsInGroup1 = Math.floor(totalCodewords / ecTotalBlocks);
    const dataCodewordsInGroup1 = Math.floor(dataTotalCodewords / ecTotalBlocks);
    const dataCodewordsInGroup2 = dataCodewordsInGroup1 + 1;
    const ecCount = totalCodewordsInGroup1 - dataCodewordsInGroup1;
    const rs = new ReedSolomonEncoder(ecCount);
    let offset = 0;
    const dcData = new Array(ecTotalBlocks);
    const ecData = new Array(ecTotalBlocks);
    let maxDataSize = 0;
    const buffer = new Uint8Array(bitBuffer2.buffer);
    for (let b = 0; b < ecTotalBlocks; b++) {
      const dataSize = b < blocksInGroup1 ? dataCodewordsInGroup1 : dataCodewordsInGroup2;
      dcData[b] = buffer.slice(offset, offset + dataSize);
      ecData[b] = rs.encode(dcData[b]);
      offset += dataSize;
      maxDataSize = Math.max(maxDataSize, dataSize);
    }
    const data = new Uint8Array(totalCodewords);
    let index = 0;
    let i, r;
    for (i = 0; i < maxDataSize; i++) {
      for (r = 0; r < ecTotalBlocks; r++) {
        if (i < dcData[r].length) {
          data[index++] = dcData[r][i];
        }
      }
    }
    for (i = 0; i < ecCount; i++) {
      for (r = 0; r < ecTotalBlocks; r++) {
        data[index++] = ecData[r][i];
      }
    }
    return data;
  }
  function createSymbol(data, version2, errorCorrectionLevel2, maskPattern2) {
    let segments2;
    if (Array.isArray(data)) {
      segments2 = Segments.fromArray(data);
    } else if (typeof data === "string") {
      let estimatedVersion = version2;
      if (!estimatedVersion) {
        const rawSegments = Segments.rawSplit(data);
        estimatedVersion = Version.getBestVersionForData(rawSegments, errorCorrectionLevel2);
      }
      segments2 = Segments.fromString(data, estimatedVersion || 40);
    } else {
      throw new Error("Invalid data");
    }
    const bestVersion = Version.getBestVersionForData(segments2, errorCorrectionLevel2);
    if (!bestVersion) {
      throw new Error("The amount of data is too big to be stored in a QR Code");
    }
    if (!version2) {
      version2 = bestVersion;
    } else if (version2 < bestVersion) {
      throw new Error(
        "\nThe chosen QR Code version cannot contain this amount of data.\nMinimum version required to store current data is: " + bestVersion + ".\n"
      );
    }
    const dataBits = createData(version2, errorCorrectionLevel2, segments2);
    const moduleCount = Utils.getSymbolSize(version2);
    const modules = new BitMatrix(moduleCount);
    setupFinderPattern(modules, version2);
    setupTimingPattern(modules);
    setupAlignmentPattern(modules, version2);
    setupFormatInfo(modules, errorCorrectionLevel2, 0);
    if (version2 >= 7) {
      setupVersionInfo(modules, version2);
    }
    setupData(modules, dataBits);
    if (isNaN(maskPattern2)) {
      maskPattern2 = MaskPattern.getBestMask(
        modules,
        setupFormatInfo.bind(null, modules, errorCorrectionLevel2)
      );
    }
    MaskPattern.applyMask(maskPattern2, modules);
    setupFormatInfo(modules, errorCorrectionLevel2, maskPattern2);
    return {
      modules,
      version: version2,
      errorCorrectionLevel: errorCorrectionLevel2,
      maskPattern: maskPattern2,
      segments: segments2
    };
  }
  qrcode.create = function create(data, options) {
    if (typeof data === "undefined" || data === "") {
      throw new Error("No input text");
    }
    let errorCorrectionLevel2 = ECLevel.M;
    let version2;
    let mask;
    if (typeof options !== "undefined") {
      errorCorrectionLevel2 = ECLevel.from(options.errorCorrectionLevel, ECLevel.M);
      version2 = Version.from(options.version);
      mask = MaskPattern.from(options.maskPattern);
      if (options.toSJISFunc) {
        Utils.setToSJISFunction(options.toSJISFunc);
      }
    }
    return createSymbol(data, version2, errorCorrectionLevel2, mask);
  };
  return qrcode;
}
var canvas = {};
var utils = {};
var hasRequiredUtils;
function requireUtils() {
  if (hasRequiredUtils) return utils;
  hasRequiredUtils = 1;
  (function(exports) {
    function hex2rgba(hex) {
      if (typeof hex === "number") {
        hex = hex.toString();
      }
      if (typeof hex !== "string") {
        throw new Error("Color should be defined as hex string");
      }
      let hexCode = hex.slice().replace("#", "").split("");
      if (hexCode.length < 3 || hexCode.length === 5 || hexCode.length > 8) {
        throw new Error("Invalid hex color: " + hex);
      }
      if (hexCode.length === 3 || hexCode.length === 4) {
        hexCode = Array.prototype.concat.apply([], hexCode.map(function(c) {
          return [c, c];
        }));
      }
      if (hexCode.length === 6) hexCode.push("F", "F");
      const hexValue = parseInt(hexCode.join(""), 16);
      return {
        r: hexValue >> 24 & 255,
        g: hexValue >> 16 & 255,
        b: hexValue >> 8 & 255,
        a: hexValue & 255,
        hex: "#" + hexCode.slice(0, 6).join("")
      };
    }
    exports.getOptions = function getOptions(options) {
      if (!options) options = {};
      if (!options.color) options.color = {};
      const margin = typeof options.margin === "undefined" || options.margin === null || options.margin < 0 ? 4 : options.margin;
      const width = options.width && options.width >= 21 ? options.width : void 0;
      const scale = options.scale || 4;
      return {
        width,
        scale: width ? 4 : scale,
        margin,
        color: {
          dark: hex2rgba(options.color.dark || "#000000ff"),
          light: hex2rgba(options.color.light || "#ffffffff")
        },
        type: options.type,
        rendererOpts: options.rendererOpts || {}
      };
    };
    exports.getScale = function getScale(qrSize, opts) {
      return opts.width && opts.width >= qrSize + opts.margin * 2 ? opts.width / (qrSize + opts.margin * 2) : opts.scale;
    };
    exports.getImageWidth = function getImageWidth(qrSize, opts) {
      const scale = exports.getScale(qrSize, opts);
      return Math.floor((qrSize + opts.margin * 2) * scale);
    };
    exports.qrToImageData = function qrToImageData(imgData, qr, opts) {
      const size = qr.modules.size;
      const data = qr.modules.data;
      const scale = exports.getScale(size, opts);
      const symbolSize = Math.floor((size + opts.margin * 2) * scale);
      const scaledMargin = opts.margin * scale;
      const palette = [opts.color.light, opts.color.dark];
      for (let i = 0; i < symbolSize; i++) {
        for (let j = 0; j < symbolSize; j++) {
          let posDst = (i * symbolSize + j) * 4;
          let pxColor = opts.color.light;
          if (i >= scaledMargin && j >= scaledMargin && i < symbolSize - scaledMargin && j < symbolSize - scaledMargin) {
            const iSrc = Math.floor((i - scaledMargin) / scale);
            const jSrc = Math.floor((j - scaledMargin) / scale);
            pxColor = palette[data[iSrc * size + jSrc] ? 1 : 0];
          }
          imgData[posDst++] = pxColor.r;
          imgData[posDst++] = pxColor.g;
          imgData[posDst++] = pxColor.b;
          imgData[posDst] = pxColor.a;
        }
      }
    };
  })(utils);
  return utils;
}
var hasRequiredCanvas;
function requireCanvas() {
  if (hasRequiredCanvas) return canvas;
  hasRequiredCanvas = 1;
  (function(exports) {
    const Utils = requireUtils();
    function clearCanvas(ctx, canvas2, size) {
      ctx.clearRect(0, 0, canvas2.width, canvas2.height);
      if (!canvas2.style) canvas2.style = {};
      canvas2.height = size;
      canvas2.width = size;
      canvas2.style.height = size + "px";
      canvas2.style.width = size + "px";
    }
    function getCanvasElement() {
      try {
        return document.createElement("canvas");
      } catch (e) {
        throw new Error("You need to specify a canvas element");
      }
    }
    exports.render = function render(qrData, canvas2, options) {
      let opts = options;
      let canvasEl = canvas2;
      if (typeof opts === "undefined" && (!canvas2 || !canvas2.getContext)) {
        opts = canvas2;
        canvas2 = void 0;
      }
      if (!canvas2) {
        canvasEl = getCanvasElement();
      }
      opts = Utils.getOptions(opts);
      const size = Utils.getImageWidth(qrData.modules.size, opts);
      const ctx = canvasEl.getContext("2d");
      const image = ctx.createImageData(size, size);
      Utils.qrToImageData(image.data, qrData, opts);
      clearCanvas(ctx, canvasEl, size);
      ctx.putImageData(image, 0, 0);
      return canvasEl;
    };
    exports.renderToDataURL = function renderToDataURL(qrData, canvas2, options) {
      let opts = options;
      if (typeof opts === "undefined" && (!canvas2 || !canvas2.getContext)) {
        opts = canvas2;
        canvas2 = void 0;
      }
      if (!opts) opts = {};
      const canvasEl = exports.render(qrData, canvas2, opts);
      const type = opts.type || "image/png";
      const rendererOpts = opts.rendererOpts || {};
      return canvasEl.toDataURL(type, rendererOpts.quality);
    };
  })(canvas);
  return canvas;
}
var svgTag = {};
var hasRequiredSvgTag;
function requireSvgTag() {
  if (hasRequiredSvgTag) return svgTag;
  hasRequiredSvgTag = 1;
  const Utils = requireUtils();
  function getColorAttrib(color, attrib) {
    const alpha = color.a / 255;
    const str = attrib + '="' + color.hex + '"';
    return alpha < 1 ? str + " " + attrib + '-opacity="' + alpha.toFixed(2).slice(1) + '"' : str;
  }
  function svgCmd(cmd, x, y) {
    let str = cmd + x;
    if (typeof y !== "undefined") str += " " + y;
    return str;
  }
  function qrToPath(data, size, margin) {
    let path = "";
    let moveBy = 0;
    let newRow = false;
    let lineLength = 0;
    for (let i = 0; i < data.length; i++) {
      const col = Math.floor(i % size);
      const row = Math.floor(i / size);
      if (!col && !newRow) newRow = true;
      if (data[i]) {
        lineLength++;
        if (!(i > 0 && col > 0 && data[i - 1])) {
          path += newRow ? svgCmd("M", col + margin, 0.5 + row + margin) : svgCmd("m", moveBy, 0);
          moveBy = 0;
          newRow = false;
        }
        if (!(col + 1 < size && data[i + 1])) {
          path += svgCmd("h", lineLength);
          lineLength = 0;
        }
      } else {
        moveBy++;
      }
    }
    return path;
  }
  svgTag.render = function render(qrData, options, cb) {
    const opts = Utils.getOptions(options);
    const size = qrData.modules.size;
    const data = qrData.modules.data;
    const qrcodesize = size + opts.margin * 2;
    const bg = !opts.color.light.a ? "" : "<path " + getColorAttrib(opts.color.light, "fill") + ' d="M0 0h' + qrcodesize + "v" + qrcodesize + 'H0z"/>';
    const path = "<path " + getColorAttrib(opts.color.dark, "stroke") + ' d="' + qrToPath(data, size, opts.margin) + '"/>';
    const viewBox = 'viewBox="0 0 ' + qrcodesize + " " + qrcodesize + '"';
    const width = !opts.width ? "" : 'width="' + opts.width + '" height="' + opts.width + '" ';
    const svgTag2 = '<svg xmlns="http://www.w3.org/2000/svg" ' + width + viewBox + ' shape-rendering="crispEdges">' + bg + path + "</svg>\n";
    if (typeof cb === "function") {
      cb(null, svgTag2);
    }
    return svgTag2;
  };
  return svgTag;
}
var hasRequiredBrowser;
function requireBrowser() {
  if (hasRequiredBrowser) return browser;
  hasRequiredBrowser = 1;
  const canPromise2 = requireCanPromise();
  const QRCode2 = requireQrcode();
  const CanvasRenderer = requireCanvas();
  const SvgRenderer = requireSvgTag();
  function renderCanvas(renderFunc, canvas2, text, opts, cb) {
    const args = [].slice.call(arguments, 1);
    const argsNum = args.length;
    const isLastArgCb = typeof args[argsNum - 1] === "function";
    if (!isLastArgCb && !canPromise2()) {
      throw new Error("Callback required as last argument");
    }
    if (isLastArgCb) {
      if (argsNum < 2) {
        throw new Error("Too few arguments provided");
      }
      if (argsNum === 2) {
        cb = text;
        text = canvas2;
        canvas2 = opts = void 0;
      } else if (argsNum === 3) {
        if (canvas2.getContext && typeof cb === "undefined") {
          cb = opts;
          opts = void 0;
        } else {
          cb = opts;
          opts = text;
          text = canvas2;
          canvas2 = void 0;
        }
      }
    } else {
      if (argsNum < 1) {
        throw new Error("Too few arguments provided");
      }
      if (argsNum === 1) {
        text = canvas2;
        canvas2 = opts = void 0;
      } else if (argsNum === 2 && !canvas2.getContext) {
        opts = text;
        text = canvas2;
        canvas2 = void 0;
      }
      return new Promise(function(resolve, reject) {
        try {
          const data = QRCode2.create(text, opts);
          resolve(renderFunc(data, canvas2, opts));
        } catch (e) {
          reject(e);
        }
      });
    }
    try {
      const data = QRCode2.create(text, opts);
      cb(null, renderFunc(data, canvas2, opts));
    } catch (e) {
      cb(e);
    }
  }
  browser.create = QRCode2.create;
  browser.toCanvas = renderCanvas.bind(null, CanvasRenderer.render);
  browser.toDataURL = renderCanvas.bind(null, CanvasRenderer.renderToDataURL);
  browser.toString = renderCanvas.bind(null, function(data, _, opts) {
    return SvgRenderer.render(data, opts);
  });
  return browser;
}
var browserExports = requireBrowser();
const QRCode = /* @__PURE__ */ getDefaultExportFromCjs(browserExports);
function sniptoComponent() {
  return {
    slug: null,
    // Unique identifier of the Snipto (URL slug)
    key: null,
    // Derived AES-CBC CryptoKey object
    iv: null,
    // Initialization Vector (hex string)
    payload: "",
    // Decrypted plaintext payload
    expires_at: "",
    // Expiry time of this Snipto (from server)
    views_remaining: 0,
    // How many times this Snipto can still be viewed
    loading: true,
    // UI state: true while fetching/decrypting
    showPayload: false,
    // UI toggle: decrypted content visible
    showForm: false,
    // UI toggle: show new snipto form
    showSuccess: false,
    // UI toggle: show success screen
    errorMessage: "",
    // Error messages for user
    userInput: "",
    // Text entered when creating a new Snipto
    fullUrl: "",
    // Full shareable URL (with key in hash)
    showToast: false,
    // Toast notification state
    calledInit: false,
    // Prevent multiple init calls
    // ------------------------------
    // Initialization (view mode)
    // ------------------------------
    /**
     * Fetch an existing Snipto (if slug present), derive key, and decrypt payload.
     * Handles error cases (missing key, invalid ciphertext, expired/deleted sniptos).
     */
    async init() {
      this.slug = this.$el.dataset.slug || "";
      if (this.calledInit) return;
      this.calledInit = true;
      if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
        document.documentElement.classList.add("dark");
      }
      if (!this.slug) {
        this.showForm = true;
        this.loading = false;
        this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
        return;
      }
      try {
        const res = await fetch(`/api/snipto/${this.slug}`, {
          method: "GET",
          headers: { "Accept": "application/json" }
        });
        if (res.status === 404) {
          this.showForm = true;
          this.$nextTick(() => setTimeout(() => this.$refs.textarea?.focus(), 100));
          return;
        } else if (res.status === 429) {
          this.errorMessage = "Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.";
          return;
        }
        if (!res.ok) throw new Error("Error fetching snipto");
        const data = await res.json();
        const shortSecret = new URLSearchParams(window.location.hash.substring(1)).get("k");
        if (!shortSecret) {
          this.errorMessage = "Missing decryption key in URL.";
          return;
        }
        this.iv = data.iv;
        this.key = await this.deriveKey(shortSecret, this.iv);
        let decrypted;
        try {
          decrypted = await this.decryptPayload(data.payload, this.key, this.iv);
        } catch {
          decrypted = "";
        }
        if (!decrypted) {
          this.errorMessage = "Failed to decrypt your snipto. Please check your key.";
          return;
        }
        this.payload = decrypted.trim();
        this.expires_at = data.expires_at;
        this.views_remaining = data.views_remaining - 1;
        this.showPayload = true;
        const hashHex = await this.sha256Hex(new TextEncoder().encode(data.payload));
        await fetch(`/api/snipto/${this.slug}/viewed`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": this.getCsrfToken(),
            "Accept": "application/json"
          },
          body: JSON.stringify({ payload_hash: hashHex }),
          credentials: "same-origin"
        });
      } catch (err) {
        this.errorMessage = err.message;
      } finally {
        this.loading = false;
      }
    },
    // ------------------------------
    // Submission (create mode)
    // ------------------------------
    /**
     * Encrypt and submit a new Snipto to the server.
     * 1. Generate a random short secret (shared only via URL fragment).
     * 2. Generate a random IV (16 bytes).
     * 3. Derive AES key with PBKDF2 (salt = IV).
     * 4. Encrypt user’s plaintext.
     * 5. Send ciphertext + IV (never plaintext, never secret) to server.
     * 6. Show success UI with shareable URL (#k=secret).
     */
    async submitSnipto() {
      if (!this.userInput.trim()) return;
      this.loading = true;
      const shortSecret = this.generateShortSecret(16);
      this.iv = await this.generateRandomBytes(16);
      this.key = await this.deriveKey(shortSecret, this.iv);
      const encrypted = await this.encryptPayload(this.userInput, this.key, this.iv);
      try {
        const res = await fetch("/api/snipto", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": this.getCsrfToken(),
            "Accept": "application/json"
          },
          body: JSON.stringify({
            slug: this.slug,
            payload: encrypted,
            iv: this.iv
          }),
          credentials: "same-origin"
        });
        if (res.status === 429) {
          this.errorMessage = "Whoa, take it easy! You’ve hit your snipto limit. Give it a minute before trying again.";
          return;
        }
        const body = await res.json();
        if (!res.ok || !body.success) {
          this.errorMessage = "An error occurred. Please try again.";
          return;
        }
        this.showForm = false;
        this.showSuccess = true;
        this.fullUrl = `${window.location.origin}/${this.slug}#k=${shortSecret}`;
        QRCode.toCanvas(this.$refs.qrcode, this.fullUrl, { width: 128 });
        this.$refs.fullUrlInput.select();
      } catch {
        this.errorMessage = "An error occurred. Please try again.";
      } finally {
        this.loading = false;
      }
    },
    // ------------------------------
    // Short secret generator
    // ------------------------------
    /**
     * Generate a random alphanumeric secret of given length.
     * This is shared only in the URL fragment (#k=...), never sent to the server.
     */
    generateShortSecret(length) {
      const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
      let result = "";
      const array = new Uint8Array(length);
      window.crypto.getRandomValues(array);
      for (let i = 0; i < length; i++) {
        result += chars[array[i] % chars.length];
      }
      return result;
    },
    // ------------------------------
    // AES key derivation (PBKDF2)
    // ------------------------------
    /**
     * Derive a 256-bit AES-CBC key using PBKDF2-HMAC-SHA256.
     * @param {string} secret - User’s short secret (from URL hash fragment).
     * @param {string} ivHex - Initialization vector (hex), used as salt for PBKDF2.
     * @returns {Promise<CryptoKey>}
     */
    async deriveKey(secret, ivHex) {
      const enc = new TextEncoder();
      const keyMaterial = await crypto.subtle.importKey(
        "raw",
        enc.encode(secret),
        { name: "PBKDF2" },
        false,
        ["deriveKey"]
      );
      return crypto.subtle.deriveKey(
        {
          name: "PBKDF2",
          salt: enc.encode(ivHex),
          // Important: IV doubles as salt
          iterations: 1e5,
          hash: "SHA-256"
        },
        keyMaterial,
        { name: "AES-CBC", length: 256 },
        false,
        ["encrypt", "decrypt"]
      );
    },
    // ------------------------------
    // Encryption
    // ------------------------------
    /**
     * Encrypt plaintext using AES-CBC.
     * @param {string} plainText
     * @param {CryptoKey} key
     * @param {string} ivHex - IV in hex
     * @returns {Promise<string>} base64 ciphertext
     */
    async encryptPayload(plainText, key, ivHex) {
      const enc = new TextEncoder();
      const iv = this.hexToBytes(ivHex);
      const cipherBuffer = await crypto.subtle.encrypt(
        { name: "AES-CBC", iv },
        key,
        enc.encode(plainText)
      );
      return this.bytesToBase64(new Uint8Array(cipherBuffer));
    },
    // ------------------------------
    // Decryption
    // ------------------------------
    /**
     * Decrypt ciphertext using AES-CBC.
     * @param {string} base64Cipher - base64 encoded ciphertext
     * @param {CryptoKey} key
     * @param {string} ivHex - IV in hex
     * @returns {Promise<string>} plaintext string
     */
    async decryptPayload(base64Cipher, key, ivHex) {
      const cipherBytes = this.base64ToBytes(base64Cipher);
      const iv = this.hexToBytes(ivHex);
      const plainBuffer = await crypto.subtle.decrypt(
        { name: "AES-CBC", iv },
        key,
        cipherBytes
      );
      return new TextDecoder().decode(plainBuffer);
    },
    // ------------------------------
    // SHA-256 hashing (hex output)
    // ------------------------------
    /**
     * Compute SHA-256 of provided bytes.
     * @param {Uint8Array} bytes
     * @returns {Promise<string>} lowercase hex string
     */
    async sha256Hex(bytes) {
      const hashBuffer = await crypto.subtle.digest("SHA-256", bytes);
      return Array.from(new Uint8Array(hashBuffer)).map((b) => b.toString(16).padStart(2, "0")).join("");
    },
    // ------------------------------
    // Utility: Random bytes
    // ------------------------------
    /**
     * Generate cryptographically secure random bytes and return as hex string.
     * @param {number} length - number of bytes
     * @returns {Promise<string>}
     */
    async generateRandomBytes(length) {
      const array = new Uint8Array(length);
      window.crypto.getRandomValues(array);
      return Array.from(array).map((b) => ("00" + b.toString(16)).slice(-2)).join("");
    },
    // ------------------------------
    // Utility: Encoding helpers
    // ------------------------------
    /** Convert hex string to Uint8Array */
    hexToBytes(hex) {
      const bytes = new Uint8Array(hex.length / 2);
      for (let i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
      }
      return bytes;
    },
    /** Convert base64 string → Uint8Array */
    base64ToBytes(base64) {
      const binary = atob(base64);
      const bytes = new Uint8Array(binary.length);
      for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
      }
      return bytes;
    },
    /** Convert Uint8Array → base64 string */
    bytesToBase64(bytes) {
      let binary = "";
      for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
      }
      return btoa(binary);
    },
    // ------------------------------
    // Clipboard helper
    // ------------------------------
    /**
     * Copy the generated Snipto URL to clipboard and show a toast.
     */
    copyUrl() {
      navigator.clipboard.writeText(this.fullUrl).then(() => {
        this.showToast = true;
        setTimeout(() => this.showToast = false, 2e3);
      });
    },
    // ------------------------------
    // CSRF token helper
    // ------------------------------
    /** Fetch CSRF token from <meta> tag */
    getCsrfToken() {
      return document.querySelector('meta[name="csrf-token"]').content;
    }
  };
}
window.sniptoComponent = sniptoComponent;
