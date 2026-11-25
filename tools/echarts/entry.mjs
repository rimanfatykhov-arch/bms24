// Custom Apache ECharts build for local_kbacompany (Information Centre, issue #192).
// Only what the Information Centre needs: line chart, grid, tooltip (with its axis pointer), inside data zoom
// (wheel, drag, pinch), mark line (selected date) and mark area (missing periods), SVG renderer.
import * as core from 'echarts/core';
import {LineChart} from 'echarts/charts';
import {
    GridComponent,
    TooltipComponent,
    DataZoomInsideComponent,
    MarkLineComponent,
    MarkAreaComponent
} from 'echarts/components';
import {SVGRenderer} from 'echarts/renderers';

core.use([LineChart, GridComponent, TooltipComponent, DataZoomInsideComponent, MarkLineComponent, MarkAreaComponent,
    SVGRenderer]);

export const version = core.version;
export const init = core.init;
export const getInstanceByDom = core.getInstanceByDom;
export const dispose = core.dispose;
